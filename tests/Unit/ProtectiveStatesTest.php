<?php

use Ichiloto\Engine\Battle\BattleTargetPolicy;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolutionRequest;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\States\StateDisposition;
use Ichiloto\Engine\Entities\Stats;

final class FixedProtectionRoll implements CombatRandomSource
{
  public function __construct(private int $roll) {}

  public function nextInt(int $minimum, int $maximum): int
  {
    return max($minimum, min($maximum, $this->roll));
  }
}

function protectionBattler(string $name = 'Synthetic', int $hp = 100, int $totalHp = 100): Character
{
  return new Character($name, 1, new Stats(currentHp: $hp, totalHp: $totalHp, defence: 0, magicDefence: 0));
}

function landHit(Character $target, int $magnitude = 40, bool $blockable = true): int
{
  $before = $target->stats->currentHp;
  new CombatResolver()->resolve(new CombatResolutionRequest(
    'synthetic.hit', 'synthetic.hit#1', protectionBattler('Attacker'), $target, $magnitude,
    ResolutionKind::PHYSICAL_DAMAGE, baseAccuracy: null, criticalEligible: false, guardEligible: $blockable,
  ));

  return $before - $target->stats->currentHp;
}

it('scales blockable hits by every protective state the target carries', function () {
  $halved = protectionBattler();
  $halved->addState(new State('demi-barrier', 'Demi-Barrier', durationTurns: 2,
    disposition: StateDisposition::BENEFICIAL, damageTakenRate: 0.5));
  $blocked = protectionBattler();
  $blocked->addState(new State('barrier', 'Barrier', durationTurns: 2,
    disposition: StateDisposition::BENEFICIAL, damageTakenRate: 0.0));

  expect(landHit(protectionBattler()))->toBe(40)
    ->and(landHit($halved))->toBe(20)
    ->and(landHit($blocked))->toBe(0)
    ->and(landHit($blocked, blockable: false))->toBe(40);
});

it('reads and validates the protective rate and cover flag from state data', function () {
  $state = State::fromArray(['id' => 'cover', 'coversAllies' => true, 'damageTakenRate' => 0.5]);

  expect($state->coversAllies)->toBeTrue()
    ->and($state->damageTakenRate)->toBe(0.5)
    ->and(State::fromArray(['id' => 'plain'])->damageTakenRate)->toBe(1.0)
    ->and(fn() => State::fromArray(['id' => 'bad', 'damageTakenRate' => -0.5]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => State::fromArray(['id' => 'bad', 'damageTakenRate' => 'half']))->toThrow(InvalidArgumentException::class)
    ->and(fn() => State::fromArray(['id' => 'bad', 'coversAllies' => 'yes']))->toThrow(InvalidArgumentException::class);
});

it('stops healing ticks at maximum HP in battle and in the field', function () {
  $regen = new State('regen', 'Regen', durationTurns: 10, tickFormula: 'intval($target->stats->totalHp * 0.05)',
    disposition: StateDisposition::BENEFICIAL, fieldTickSteps: 1);
  $battle = protectionBattler(hp: 98);
  $battle->addState($regen);
  $field = protectionBattler(hp: 99);
  $field->addState($regen);

  $events = $battle->tickStates();
  $field->tickFieldStep();

  expect($battle->stats->currentHp)->toBe(100)
    ->and($events[0]['hpDelta'])->toBe(2)
    ->and($field->stats->currentHp)->toBe(100);
});

it('redirects a single-target hit meant for the weakest ally to the battler covering it', function () {
  $actor = protectionBattler('Enemy');
  $guardian = protectionBattler('Guardian');
  $guardian->addState(new State('cover', 'Cover', durationTurns: 2, disposition: StateDisposition::BENEFICIAL, coversAllies: true));
  $weakest = protectionBattler('Weakest', hp: 20);
  $healthy = protectionBattler('Healthy', hp: 90);
  $party = [$guardian, $weakest, $healthy];
  $one = new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ONE);

  expect(BattleTargetPolicy::resolveTargets($one, $actor, [$actor], $party, [$weakest]))->toBe([$guardian])
    ->and(BattleTargetPolicy::resolveTargets($one, $actor, [$actor], $party, [$healthy]))->toBe([$healthy])
    ->and(BattleTargetPolicy::resolveTargets(new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ALL), $actor, [$actor], $party))
      ->toBe($party);

  $guardian->stats->currentHp = 0;
  expect(BattleTargetPolicy::resolveTargets($one, $actor, [$actor], $party, [$weakest]))->toBe([$weakest]);
});

it('cures each listed state the target has with the authored chance', function (int $chance, int $roll, bool $cured) {
  $skill = SkillRecord::readSkill([
    'kind' => 'magic', 'name' => 'Synthetic Cure', 'description' => '', 'icon' => '', 'cost' => 0, 'cooldown' => 0,
    'occasion' => 'Battle Screen', 'scope' => ['side' => 'Ally', 'number' => 'One', 'status' => 'Alive'],
    'invocation' => ['message' => '', 'speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 0],
    'effects' => [['type' => 'remove_state', 'stateIds' => ['poison'], 'chancePercent' => $chance]],
  ]);
  $patient = protectionBattler();
  $patient->addState(new State('poison', 'Poison'));
  $context = new SkillEffectContext(protectionBattler('Healer'), $patient, random: new FixedProtectionRoll($roll));

  $skill->effects[0]->apply($context);

  expect($patient->hasState('poison'))->toBe(! $cured)
    ->and($context->secondaryOutcomes())->toBe([['type' => 'state-removal', 'id' => 'poison', 'applied' => $cured]])
    ->and(SkillRecord::writeSkill($skill)['effects'][0])
      ->toBe(['type' => 'remove_state', 'stateIds' => ['poison'], 'chancePercent' => $chance]);
})->with([
  'within the chance' => [75, 75, true],
  'beyond the chance' => [75, 76, false],
  'never' => [0, 1, false],
]);

it('keeps a certain cure free of rolls and of the default chance in exported data', function () {
  $skill = SkillRecord::readSkill([
    'kind' => 'magic', 'name' => 'Synthetic Antidote', 'description' => '', 'icon' => '', 'cost' => 0, 'cooldown' => 0,
    'occasion' => 'Battle Screen', 'scope' => ['side' => 'Ally', 'number' => 'One', 'status' => 'Alive'],
    'invocation' => ['message' => '', 'speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 0],
    'effects' => [['type' => 'remove_state', 'stateIds' => ['poison']]],
  ]);

  expect(SkillRecord::writeSkill($skill)['effects'][0])->toBe(['type' => 'remove_state', 'stateIds' => ['poison']]);
});
