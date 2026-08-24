<?php

use Ichiloto\Engine\Battle\Resolution\SeededCombatRandomSource;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPRecoverySkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\FieldSkillExecutor;
use Ichiloto\Engine\Entities\Skills\FieldSkillFailureReason;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Stats;

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
