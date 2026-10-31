<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\GuardAction;
use Ichiloto\Engine\Battle\BattleConfusion;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\AddStateSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillEffectExecutor;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\Entities\Stats;
use function Tests\Support\Battle\createTargetExecutionFixture;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

/** Plays back a scripted list of rolls, then repeats the last. */
final class ScriptedConfusionRolls implements CombatRandomSource
{
  public function __construct(private array $rolls) {}

  public function nextInt(int $minimum, int $maximum): int
  {
    $roll = count($this->rolls) > 1 ? array_shift($this->rolls) : $this->rolls[0];

    return max($minimum, min($maximum, $roll));
  }
}

function confusionState(string $id = 'heavy', int $chance = 100, int $turns = 3): State
{
  return new State($id, ucfirst($id), durationTurns: $turns, confusionChancePercent: $chance);
}

function confusionBattler(string $name, int $hp = 100): Character
{
  return new Character($name, 1, new Stats(currentHp: $hp, totalHp: 100, attack: 10, defence: 0));
}

it('reads and bounds a state\'s confusion chance', function () {
  expect(State::fromArray(['id' => 'fog', 'durationTurns' => 3, 'confusionChancePercent' => 100])->confusionChancePercent)->toBe(100)
    ->and(State::fromArray(['id' => 'plain'])->confusionChancePercent)->toBe(0)
    ->and(fn() => State::fromArray(['id' => 'bad', 'confusionChancePercent' => 101]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => State::fromArray(['id' => 'bad', 'confusionChancePercent' => -1]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => State::fromArray(['id' => 'bad', 'confusionChancePercent' => 'half']))->toThrow(InvalidArgumentException::class);
});

it('has a confused battler attack a random battler of either side but never itself', function () {
  $bearer = confusionBattler('Bearer');
  $friend = confusionBattler('Friend');
  $fallen = confusionBattler('Fallen', 0);
  $foe = confusionBattler('Foe');
  $bearer->addState(confusionState(), 100);

  // The first roll is the confusion chance, the second picks among Friend and Foe.
  $onFriend = BattleConfusion::resolve($bearer, [$bearer, $friend, $fallen], [$foe], new ScriptedConfusionRolls([100, 0]));
  $onFoe = BattleConfusion::resolve($bearer, [$bearer, $friend, $fallen], [$foe], new ScriptedConfusionRolls([100, 1]));

  expect($onFriend[0])->toBeInstanceOf(AttackAction::class)->and($onFriend[1])->toBe([$friend])
    ->and($onFoe[1])->toBe([$foe])
    ->and(BattleConfusion::resolve($bearer, [$bearer], [], new ScriptedConfusionRolls([1])))->toBeNull()
    ->and(BattleConfusion::resolve($friend, [$friend], [$foe], new ScriptedConfusionRolls([1])))->toBeNull();
});

it('lets a lighter confusion pass a turn when its chance misses', function () {
  $bearer = confusionBattler('Bearer');
  $bearer->addState(confusionState('light', 50, 2), 100);
  $bearer->addState(confusionState('heavier', 60, 3), 100);

  expect($bearer->getConfusionChancePercent())->toBe(60)
    ->and(BattleConfusion::resolve($bearer, [$bearer], [confusionBattler('Foe')], new ScriptedConfusionRolls([61])))->toBeNull()
    ->and(BattleConfusion::resolve($bearer, [$bearer], [confusionBattler('Foe')], new ScriptedConfusionRolls([60, 0])))->not->toBeNull();
});

it('wears off after its authored turns', function () {
  $bearer = confusionBattler('Bearer');
  $bearer->addState(confusionState('heavy', 100, 3), 100);
  $bearer->tickStates();
  $bearer->tickStates();
  expect($bearer->hasState('heavy'))->toBeTrue();
  $bearer->tickStates();
  expect($bearer->hasState('heavy'))->toBeFalse()->and($bearer->getConfusionChancePercent())->toBe(0);
});

it('leaves survivors of a lethal hit in the state and the fallen without it', function () {
  $registry = new ReflectionProperty(StateRegistry::class, 'states');
  $previous = $registry->getValue();
  $registry->setValue(null, ['heavy' => confusionState('heavy')]);
  $survivor = confusionBattler('Survivor', 100);
  $fallen = confusionBattler('Fallen', 5);
  $quake = new MagicSkill('Synthetic Quake', '', '', 0, 0, new ItemScope(ItemScopeSide::ENEMY, ItemScopeNumber::ALL,
    ItemScopeStatus::ALIVE), Occasion::BATTLE_SCREEN, effects: [
      new HPDamageSkillEffect('20', variance: 0), new AddStateSkillEffect('heavy'),
    ]);

  try {
    new SkillEffectExecutor()->execute($quake, confusionBattler('Caster'), [$survivor, $fallen], 'skill.quake', 'skill.quake#1');
  } finally {
    $registry->setValue(null, $previous);
  }

  expect($fallen->isKnockedOut)->toBeTrue()->and($fallen->hasState('heavy'))->toBeFalse()
    ->and($survivor->hasState('heavy'))->toBeTrue();
});

it('replaces a confused battler\'s chosen action with an attack in battle', function (bool $activeTime) {
  $fixture = createTargetExecutionFixture($activeTime, false, test()->createStub(AudioManager::class));
  [$engine, $context, $screen, $actor, , $enemies] = $fixture;
  $actor->addState(confusionState(), 100);
  $enemies[1]->stats->currentHp = 0;
  new ReflectionProperty($engine, 'random')->setValue($engine, new ScriptedConfusionRolls([1]));

  // Guarding was chosen; the only other battler still fighting is Enemy A, so the attack falls on it.
  $turn = Tests\Support\Battle\queueTargetExecution($fixture, new GuardAction('Guard'), [$actor]);

  expect($turn->action)->toBeInstanceOf(AttackAction::class)->and($turn->targets)->toBe([$enemies[0]])
    ->and($screen->fieldWindow->getCommandPlayback()?->targets)->toBe([$enemies[0]]);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 0.0);
})->with([false, true]);
