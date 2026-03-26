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
