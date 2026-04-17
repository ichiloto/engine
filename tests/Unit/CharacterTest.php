<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;

it('can create a character', function () {
  $characterName = 'John Doe';
  $currentExp = 500;
  $stats = new Stats();
  $characterBio = 'A simple character.';

  $character = new Character($characterName, $currentExp, $stats, bio: $characterBio);

  expect($character)
    ->toBeInstanceOf(Character::class)
    ->toHaveProperties(['name', 'currentExp', 'stats', 'images', 'nickname', 'maxLevel', 'bio', 'note', 'equipment', 'role'])
    ->and($character->name)
    ->toBe($characterName)
    ->and($character->currentExp)
    ->toBe($currentExp)
    ->and($character->stats)
    ->toBe($stats)
    ->and($character->bio)
    ->toBe($characterBio);
});

it('keeps stored HP and MP totals equipment-aware after equipping bonus gear', function () {
  $character = new Character('Kaelion', 0, new Stats(currentHp: 120, currentMp: 18));
  $baseTotalHp = $character->stats->totalHp;
  $baseTotalMp = $character->stats->totalMp;
  $weaponSlot = $character->equipment[0];
  $weapon = new Weapon(
    'Vital Blade',
    'Boosts maximum vitality and mana.',
      '!',
    100,
    parameterChanges: new ParameterChanges(totalHp: 50, totalMp: 10),
  );

  $weaponSlot->equipment = $weapon;
  invokeCharacterAdjustStatTotals($character);
  $character->stats->currentHp = 99999;
  $character->stats->currentMp = 99999;

  expect($character->stats->totalHp)->toBe($baseTotalHp + 50)
    ->and($character->stats->totalMp)->toBe($baseTotalMp + 10)
    ->and($character->stats->currentHp)->toBe($baseTotalHp + 50)
    ->and($character->stats->currentMp)->toBe($baseTotalMp + 10)
    ->and($character->effectiveStats->totalHp)->toBe($baseTotalHp + 50)
    ->and($character->effectiveStats->totalMp)->toBe($baseTotalMp + 10);
});

it('does not zero stats at level 100 when the top curve level is missing', function () {
  $character = new Character('Kaelion', 0, new Stats(currentHp: 120, currentMp: 18));
  $character->addExperience(PHP_INT_MAX);

  expect($character->level)->toBe(100);

  setCharacterProperty($character, 'totalHpCurve', array_slice($character->totalHpCurve, 0, 99, true));
  setCharacterProperty($character, 'totalMpCurve', array_slice($character->totalMpCurve, 0, 99, true));
  setCharacterProperty($character, 'attackCurve', array_slice($character->attackCurve, 0, 99, true));
  setCharacterProperty($character, 'defenceCurve', array_slice($character->defenceCurve, 0, 99, true));
  setCharacterProperty($character, 'magicAttackCurve', array_slice($character->magicAttackCurve, 0, 99, true));
  setCharacterProperty($character, 'magicDefenceCurve', array_slice($character->magicDefenceCurve, 0, 99, true));
  setCharacterProperty($character, 'evasionCurve', array_slice($character->evasionCurve, 0, 99, true));
  setCharacterProperty($character, 'graceCurve', array_slice($character->graceCurve, 0, 99, true));
  setCharacterProperty($character, 'speedCurve', array_slice($character->speedCurve, 0, 99, true));

  invokeCharacterAdjustStatTotals($character);

  expect($character->stats->totalHp)->toBeGreaterThan(0)
    ->and($character->stats->totalMp)->toBeGreaterThan(0)
    ->and($character->stats->attack)->toBeGreaterThan(0)
    ->and($character->stats->defence)->toBeGreaterThan(0)
    ->and($character->stats->magicAttack)->toBeGreaterThan(0)
    ->and($character->stats->magicDefence)->toBeGreaterThan(0)
    ->and($character->stats->evasion)->toBeGreaterThan(0)
    ->and($character->stats->grace)->toBeGreaterThan(0)
    ->and($character->stats->speed)->toBeGreaterThan(0);
});

it('rehydrates level curves after unserializing so level 100 stats stay non-zero', function () {
  $character = new Character('Kaelion', 0, new Stats(currentHp: 120, currentMp: 18));
  $character->addExperience(PHP_INT_MAX);

  /** @var Character $restored */
  $restored = unserialize(serialize($character), ['allowed_classes' => true]);
  invokeCharacterAdjustStatTotals($restored);

  expect($restored->level)->toBe(100)
    ->and($restored->stats->totalHp)->toBeGreaterThan(0)
    ->and($restored->stats->totalMp)->toBeGreaterThan(0)
    ->and($restored->stats->attack)->toBeGreaterThan(0)
    ->and($restored->stats->defence)->toBeGreaterThan(0)
    ->and($restored->stats->magicAttack)->toBeGreaterThan(0)
    ->and($restored->stats->magicDefence)->toBeGreaterThan(0)
    ->and($restored->stats->evasion)->toBeGreaterThan(0)
    ->and($restored->stats->grace)->toBeGreaterThan(0)
    ->and($restored->stats->speed)->toBeGreaterThan(0);
});

it('recovers invalid zero-total vitals from legacy serialized character payloads', function () {
  $character = new Character('Kaelion', 0, new Stats(currentHp: 120, currentMp: 18));
  $character->addExperience(PHP_INT_MAX);

  $payload = $character->__serialize();
  $payload['stats'] = array_merge($payload['stats']->jsonSerialize(), [
    'currentHp' => 0,
    'currentMp' => 0,
    'totalHp' => 0,
    'totalMp' => 0,
  ]);

  $restored = (new ReflectionClass(Character::class))->newInstanceWithoutConstructor();
  $restored->__unserialize($payload);

  expect($restored->stats->totalHp)->toBeGreaterThan(0)
    ->and($restored->stats->totalMp)->toBeGreaterThanOrEqual(0)
    ->and($restored->stats->currentHp)->toBe($restored->stats->totalHp)
    ->and($restored->stats->currentMp)->toBe($restored->stats->totalMp);
});

/**
 * Invokes the protected stat refresh routine on a character.
 *
 * @param Character $character The character under test.
 * @return void
 */
function invokeCharacterAdjustStatTotals(Character $character): void
{
  $method = new ReflectionMethod(Character::class, 'adjustStatTotals');
  $method->invoke($character);
}

/**
 * Sets a property value via reflection for test setup.
 *
 * @param Character $character
 * @param string $propertyName
 * @param mixed $value
 * @return void
 */
function setCharacterProperty(Character $character, string $propertyName, mixed $value): void
{
  $property = new ReflectionProperty(Character::class, $propertyName);
  $property->setValue($character, $value);
}
