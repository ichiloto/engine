<?php

use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\SeededCombatRandomSource;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\AddStateSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\ModifyStatStageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPRecoverySkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\RemoveStateSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\SkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\FieldSkillExecutor;
use Ichiloto\Engine\Entities\Skills\FieldSkillFailureReason;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\Entities\Stats;

beforeEach(function () {
  $this->stateRegistry = new ReflectionProperty(StateRegistry::class, 'states')->getValue();
});

afterEach(function () {
  new ReflectionProperty(StateRegistry::class, 'states')->setValue(null, $this->stateRegistry);
});

it('requires an explicit target for one-ally field magic without spending MP', function () {
  [$party, $caster, $ally] = makeFieldSkillParty();
  $skill = makeFieldCure();
  $startingMp = $caster->stats->currentMp;

  $result = (new FieldSkillExecutor())->execute($skill, $caster, $party);

  expect($result->succeeded)->toBeFalse()
    ->and($result->failureReason)->toBe(FieldSkillFailureReason::TARGET_REQUIRED)
    ->and($caster->stats->currentMp)->toBe($startingMp)
    ->and($ally->stats->currentHp)->toBe(30);
});

it('heals the selected ally and charges the spell cost exactly once', function () {
  [$party, $caster, $ally] = makeFieldSkillParty();
  $skill = makeFieldCure();

  $result = (new FieldSkillExecutor())->execute($skill, $caster, $party, $ally);

  expect($result->succeeded)->toBeTrue()
    ->and($result->targets)->toBe([$ally])
    ->and($result->actionResult?->actualHpRestored())->toBe(30)
    ->and($caster->stats->currentMp)->toBe(17)
    ->and($ally->stats->currentHp)->toBe(60);
});

it('refunds MP when the selected target cannot benefit from the spell', function () {
  [$party, $caster, $ally] = makeFieldSkillParty();
  $ally->stats->currentHp = $ally->stats->totalHp;
  $startingMp = $caster->stats->currentMp;

  $result = (new FieldSkillExecutor())->execute(makeFieldCure(), $caster, $party, $ally);

  expect($result->succeeded)->toBeFalse()
    ->and($result->failureReason)->toBe(FieldSkillFailureReason::NO_EFFECT)
    ->and($caster->stats->currentMp)->toBe($startingMp)
    ->and($ally->stats->currentHp)->toBe($ally->stats->totalHp);
});

it('applies all-ally magic to every eligible party member for one cost', function () {
  [$party, $caster, $ally] = makeFieldSkillParty();
  $caster->stats->currentHp = 50;
  $skill = new MagicSkill(
    'Cure All',
    'Restores the whole party.',
    '+',
    7,
    0,
    new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ALL),
    Occasion::MENU_SCREEN,
    effects: [new HPRecoverSkillEffect('20', variance: 0.0)],
  );

  $result = (new FieldSkillExecutor())->execute($skill, $caster, $party);

  expect($result->succeeded)->toBeTrue()
    ->and($result->targets)->toBe([$caster, $ally])
    ->and($caster->stats->currentMp)->toBe(13)
    ->and($caster->stats->currentHp)->toBe(70)
    ->and($ally->stats->currentHp)->toBe(50);
});

it('supports user-scoped recovery without a target prompt', function () {
  [$party, $caster] = makeFieldSkillParty();
  $caster->stats->currentMp = 10;
  $skill = new MagicSkill(
    'Focus',
    'Restores the caster\'s MP.',
    '*',
    2,
    0,
    new ItemScope(ItemScopeSide::USER),
    Occasion::MENU_SCREEN,
    effects: [new MPRecoverySkillEffect('5', variance: 0.0)],
  );

  $result = (new FieldSkillExecutor())->execute($skill, $caster, $party);

  expect($result->succeeded)->toBeTrue()
    ->and($result->targets)->toBe([$caster])
    ->and($caster->stats->currentMp)->toBe(13);
});

it('filters targets by alive and dead scope status', function () {
  [$party, $caster, $ally] = makeFieldSkillParty();
  $ally->stats->currentHp = 0;
  $skill = makeFieldCure();
  $startingMp = $caster->stats->currentMp;

  $result = (new FieldSkillExecutor())->execute($skill, $caster, $party, $ally);

  expect($result->succeeded)->toBeFalse()
    ->and($result->failureReason)->toBe(FieldSkillFailureReason::INVALID_TARGET)
    ->and($caster->stats->currentMp)->toBe($startingMp);
});

it('rejects battle-only, enemy-scoped and effectless field requests without spending MP', function () {
  [$party, $caster, $ally] = makeFieldSkillParty();
  $executor = new FieldSkillExecutor();
  $startingMp = $caster->stats->currentMp;
  $battleOnly = new MagicSkill(
    'Fire',
    'Battle magic.',
    '*',
    4,
    0,
    new ItemScope(ItemScopeSide::ENEMY),
    Occasion::BATTLE_SCREEN,
    effects: [new HPRecoverSkillEffect('20', variance: 0.0)],
  );
  $enemyScoped = new MagicSkill(
    'Field Fire',
    'Invalid field target.',
    '*',
    4,
    0,
    new ItemScope(ItemScopeSide::ENEMY),
    Occasion::MENU_SCREEN,
    effects: [new HPRecoverSkillEffect('20', variance: 0.0)],
  );
  $effectless = new MagicSkill(
    'Warp',
    'Not wired yet.',
    '*',
    4,
    0,
    new ItemScope(ItemScopeSide::USER),
    Occasion::MENU_SCREEN,
  );

  expect($executor->execute($battleOnly, $caster, $party, $ally)->failureReason)
    ->toBe(FieldSkillFailureReason::WRONG_OCCASION)
    ->and($executor->execute($enemyScoped, $caster, $party)->failureReason)
    ->toBe(FieldSkillFailureReason::UNSUPPORTED_SCOPE)
    ->and($executor->execute($effectless, $caster, $party)->failureReason)
    ->toBe(FieldSkillFailureReason::NO_EFFECTS)
    ->and($caster->stats->currentMp)->toBe($startingMp);
});

it('supports authored random ally counts without selecting the same target twice', function () {
  [$party, $caster, $ally] = makeFieldSkillParty();
  $third = makeFieldSkillCharacter('Third', 20, 20);
  $party->addMember($third);
  $skill = new MagicSkill(
    'Twin Mend',
    'Restores two random allies.',
    '+',
    5,
    0,
    new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::RANDOM, ItemScopeStatus::ALIVE, 2),
    Occasion::MENU_SCREEN,
    effects: [new HPRecoverSkillEffect('10', variance: 0.0)],
  );

  $result = (new FieldSkillExecutor(random: new SeededCombatRandomSource(12)))
    ->execute($skill, $caster, $party);

  expect($result->succeeded)->toBeTrue()
    ->and($result->targets)->toHaveCount(2)
    ->and(array_unique(array_map('spl_object_id', $result->targets)))->toHaveCount(2)
    ->and($caster->stats->currentMp)->toBe(15);
});

it('charges for real stat-stage changes on either the target or caster', function (bool $affectsUser, int $initial, int $delta) {
  [$party, $caster, $ally] = makeFieldSkillParty();
  $recipient = $affectsUser ? $caster : $ally;
  $recipient->setStatStage('attack', $initial);
  $skill = makeFieldEffectSkill(new ModifyStatStageSkillEffect('attack', $delta, $affectsUser));

  $result = new FieldSkillExecutor()->execute($skill, $caster, $party, $ally);

  expect($recipient->getStatStage('attack'))->toBe($initial + $delta)
    ->and($result->succeeded)->toBeTrue()
    ->and($result->failureReason)->toBeNull()
    ->and($caster->stats->currentMp)->toBe(17)
    ->and(($affectsUser ? $ally : $caster)->getStatStage('attack'))->toBe(0);
})->with([
  'target buff' => [false, 0, 1],
  'caster buff' => [true, 0, 1],
  'target debuff' => [false, 0, -1],
  'caster debuff' => [true, 0, -1],
  'remove a debuff' => [false, -1, 1],
]);

it('refunds MP for stat-stage requests with no net effect', function (string $stat, int $initial, array $deltas) {
  [$party, $caster, $ally] = makeFieldSkillParty();
  if (in_array($stat, Character::buffableStats(), true)) { $ally->setStatStage($stat, $initial); }
  $skill = makeFieldEffectSkill(...array_map(
    static fn(int $delta): SkillEffect => new ModifyStatStageSkillEffect($stat, $delta),
    $deltas,
  ));

  $result = new FieldSkillExecutor()->execute($skill, $caster, $party, $ally);

  expect($ally->getStatStage($stat))->toBe($initial)
    ->and($result->succeeded)->toBeFalse()
    ->and($result->failureReason)->toBe(FieldSkillFailureReason::NO_EFFECT)
    ->and($caster->stats->currentMp)->toBe(20);
})->with([
  'upper cap' => ['attack', 4, [1]],
  'lower cap' => ['attack', -4, [-1]],
  'zero delta' => ['attack', 0, [0]],
  'unsupported stat' => ['unknown', 0, [1]],
  'cancelled changes' => ['attack', 0, [1, -1]],
]);

it('accounts for state infliction and removal regardless of persistence and refunds repeated no-ops', function (bool $persistent) {
  [$party, $caster, $ally] = makeFieldSkillParty();
  $state = new State('field-focus', 'Field Focus', durationTurns: 2, persistsAfterBattle: $persistent);
  new ReflectionProperty(StateRegistry::class, 'states')->setValue(null, [$state->id => $state]);
  $executor = new FieldSkillExecutor(random: new SeededCombatRandomSource(12));
  $inflict = makeFieldEffectSkill(new AddStateSkillEffect($state->id));
  $cure = makeFieldEffectSkill(new RemoveStateSkillEffect([$state->id]));

  $added = $executor->execute($inflict, $caster, $party, $ally);
  expect($ally->hasState($state->id))->toBeTrue()
    ->and($ally->states[0]->remainingTurns)->toBe(2)
    ->and($added->succeeded)->toBeTrue()
    ->and($caster->stats->currentMp)->toBe(17);

  $duplicate = $executor->execute($inflict, $caster, $party, $ally);
  expect($duplicate->failureReason)->toBe(FieldSkillFailureReason::NO_EFFECT)
    ->and($ally->states)->toHaveCount(1)
    ->and($caster->stats->currentMp)->toBe(17);

  $removed = $executor->execute($cure, $caster, $party, $ally);
  expect($ally->hasState($state->id))->toBeFalse()
    ->and($removed->succeeded)->toBeTrue()
    ->and($caster->stats->currentMp)->toBe(14);

  $absent = $executor->execute($cure, $caster, $party, $ally);
  expect($absent->failureReason)->toBe(FieldSkillFailureReason::NO_EFFECT)
    ->and($caster->stats->currentMp)->toBe(14);
})->with([false, true]);

it('refunds MP when a state effect is resisted or misses', function (float $resistance, int $chance) {
  [$party, $caster, $ally] = makeFieldSkillParty();
  $state = new State('field-focus', 'Field Focus', durationTurns: 2);
  new ReflectionProperty(StateRegistry::class, 'states')->setValue(null, [$state->id => $state]);
  $ally->setStateResistances([$state->id => $resistance]);
  $random = $this->createMock(CombatRandomSource::class);
  $random->method('nextInt')->willReturn(100);
  $skill = makeFieldEffectSkill(new AddStateSkillEffect($state->id, $chance));

  $result = new FieldSkillExecutor(random: $random)->execute($skill, $caster, $party, $ally);

  expect($ally->hasState($state->id))->toBeFalse()
    ->and($result->succeeded)->toBeFalse()
    ->and($result->failureReason)->toBe(FieldSkillFailureReason::NO_EFFECT)
    ->and($caster->stats->currentMp)->toBe(20);
})->with([
  'immune' => [0.0, 100],
  'zero chance' => [1.0, 0],
  'failed roll' => [1.0, 50],
]);

function makeFieldEffectSkill(SkillEffect ...$effects): MagicSkill
{
  return new MagicSkill(
    'Field Effect',
    'Applies an effect to one ally.',
    '*',
    3,
    0,
    new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE),
    Occasion::MENU_SCREEN,
    effects: $effects,
  );
}

/** @return array{Party, Character, Character} */
function makeFieldSkillParty(): array
{
  $party = new Party();
  $caster = makeFieldSkillCharacter('Liora', 80, 20);
  $ally = makeFieldSkillCharacter('Kaelion', 30, 10);
  $party->addMember($caster);
  $party->addMember($ally);

  return [$party, $caster, $ally];
}

function makeFieldSkillCharacter(string $name, int $currentHp, int $currentMp): Character
{
  $character = new Character($name, 0, new Stats());
  $character->stats->totalHp = 100;
  $character->stats->currentHp = $currentHp;
  $character->stats->totalMp = 20;
  $character->stats->currentMp = $currentMp;

  return $character;
}

function makeFieldCure(): MagicSkill
{
  return new MagicSkill(
    'Cure',
    'Restores one ally.',
    '+',
    3,
    0,
    new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE, ItemScopeStatus::ALIVE),
    Occasion::ALWAYS,
    effects: [new HPRecoverSkillEffect('30', variance: 0.0)],
  );
}
