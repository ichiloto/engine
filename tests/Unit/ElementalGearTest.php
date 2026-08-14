<?php

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\ElementalDamage;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\Stats;

/**
 * Builds a character with empty stats and default equipment slots.
 */
function gearedCharacter(): Character
{
  return new Character('Kaelion', 0, new Stats(currentHp: 100, totalHp: 100));
}

/**
 * Equips a piece into the first slot that accepts it.
 */
function equipPiece(Character $character, object $piece): void
{
  foreach ($character->equipment as $slot) {
    if ($slot->acceptsType === $piece::class && $slot->equipment === null) {
      $slot->equipment = $piece;

      return;
    }
  }

  throw new RuntimeException('No free slot accepts ' . $piece::class);
}

it('is neutral to every element in plain clothes', function () {
  expect(gearedCharacter()->getElementMultiplier('Fire'))->toBe(1.0)
    ->and(gearedCharacter()->getElementMultiplier(null))->toBe(1.0);
});

it('halves an element a warding piece resists', function () {
  $character = gearedCharacter();
  equipPiece($character, new Armor('Flame Ward', '', '🛡', 0, elementAffinities: ['Fire' => 0.5]));

  // The ask that started this: regular defence and elemental defence on one
  // piece. The parameterChanges carry the first; this carries the second.
  expect($character->getElementMultiplier('Fire'))->toBe(0.5)
    ->and($character->getElementMultiplier('Ice'))->toBe(1.0);
});

it('multiplies stacked wards', function () {
  $character = gearedCharacter();
  equipPiece($character, new Armor('Flame Ward', '', '🛡', 0, elementAffinities: ['Fire' => 0.5]));
  equipPiece($character, new Armor('Ember Helm', '', '🛡', 0, elementAffinities: ['Fire' => 0.5]));

  expect($character->getElementMultiplier('Fire'))->toBe(0.25);
});

it('keeps absorbing once anything absorbs', function () {
  $character = gearedCharacter();
  equipPiece($character, new Armor('Pyre Heart', '', '🛡', 0, elementAffinities: ['Fire' => -1.0]));
  equipPiece($character, new Armor('Pyre Shell', '', '🛡', 0, elementAffinities: ['Fire' => -1.0]));

  // -1 x -1 is +1, and stacking wards must never turn healing back into
  // harm: the clamp keeps the sign.
  expect($character->getElementMultiplier('Fire'))->toBe(-1.0);
});

it('lets a null piece nullify even an absorb', function () {
  $character = gearedCharacter();
  equipPiece($character, new Armor('Void Plate', '', '🛡', 0, elementAffinities: ['Fire' => 0.0]));
  equipPiece($character, new Armor('Pyre Heart', '', '🛡', 0, elementAffinities: ['Fire' => -1.0]));

  expect($character->getElementMultiplier('Fire'))->toBe(0.0);
});

it('reads affinities without caring about case', function () {
  $character = gearedCharacter();
  equipPiece($character, new Armor('Flame Ward', '', '🛡', 0, elementAffinities: ['fire' => 0.5]));

  expect($character->getElementMultiplier('Fire'))->toBe(0.5);
});

it('scales incoming damage and heals on absorb', function () {
  $character = gearedCharacter();
  equipPiece($character, new Armor('Pyre Heart', '', '🛡', 0, elementAffinities: ['Fire' => -1.0]));

  expect(ElementalDamage::scale($character, 'Fire', 40))->toBe(-40)
    ->and($character->lastElementReaction)->toBe('ABSORB');
});

it('imbues basic attacks with the weapon element', function () {
  $character = gearedCharacter();
  equipPiece($character, new Weapon('Flame Brand', '', '🗡', 0, element: 'Fire', parameterChanges: new ParameterChanges(attack: 40)));

  expect($character->getAttackElement())->toBe('Fire');
});

it('attacks neutrally with a plain weapon', function () {
  $character = gearedCharacter();
  equipPiece($character, new Weapon('Wooden Sword', '', '🗡', 0, parameterChanges: new ParameterChanges(attack: 5)));

  expect($character->getAttackElement())->toBeNull();
});

it('lands a weapon element on an enemy weakness in a real basic attack', function () {
  $character = new Character(
    'Kaelion',
    0,
    // Grace 5 pins the hit chance at 100, so the attack always lands.
    new Stats(currentHp: 100, totalHp: 100, attack: 20, grace: 5),
  );
  equipPiece($character, new Weapon('Flame Brand', '', '🗡', 0, element: 'Fire'));

  $spriteDirectory = sys_get_temp_dir() . '/elemental-gear-' . uniqid();
  mkdir($spriteDirectory . '/assets/Graphics/Enemies', 0o777, true);
  file_put_contents($spriteDirectory . '/assets/Graphics/Enemies/mite.txt', "..\n");
  $previousDirectory = (string) getcwd();
  chdir($spriteDirectory);

  try {
    $enemy = new Enemy(
      'Frost Mite',
      1,
      new Stats(currentHp: 500, totalHp: 500, defence: 0, evasion: 0),
      'mite',
      new \Ichiloto\Engine\Battle\BattleRewards(1, 1, []),
      [],
      elementAffinities: ['Fire' => 2.0],
    );
  } finally {
    chdir($previousDirectory);
  }

  new AttackAction('Attack')->execute($character, [$enemy]);

  $dealt = 500 - $enemy->stats->currentHp;

  // Base damage is attack (20) minus half of zero defence; doubled by the
  // weakness to 40, or 60 on the 6% critical roll.
  expect(in_array($dealt, [40, 60], true))
    ->toBeTrue("Expected a doubled 40 (or 60 critical), got {$dealt}.")
    ->and($enemy->lastElementReaction)->toBe('WEAK!');
});
