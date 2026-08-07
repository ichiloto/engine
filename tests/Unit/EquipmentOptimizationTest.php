<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;

function makeTestWeapon(string $name, int $attack, int $quantity = 1): Weapon
{
  return Weapon::fromArray([
    'name' => $name,
    'description' => "The $name.",
    'icon' => '/',
    'quantity' => $quantity,
    'parameterChanges' => new ParameterChanges(attack: $attack),
  ]);
}

function makeTestArmor(string $name, int $defence, int $quantity = 1): Armor
{
  return Armor::fromArray([
    'name' => $name,
    'description' => "The $name.",
    'icon' => 'O',
    'quantity' => $quantity,
    'parameterChanges' => new ParameterChanges(defence: $defence),
  ]);
}

it('rates equipment by the sum of its parameter changes', function () {
  $weapon = makeTestWeapon('Long Sword', 12);

  expect($weapon->rating)->toBe(12);
});

it('returns the better rated of two equipment entries', function () {
  $weak = makeTestWeapon('Wooden Sword', 1);
  $strong = makeTestWeapon('Long Sword', 12);

  expect(Equipment::getBetterRated($weak, $strong))->toBe($strong)
    ->and(Equipment::getBetterRated($strong, $weak))->toBe($strong);
});

it('optimizes each slot with the best-rated compatible equipment', function () {
  $party = new Party();
  $character = new Character('One', 0, new Stats());
  $party->addMember($character);

  $weakWeapon = makeTestWeapon('Wooden Sword', 1);
  $strongWeapon = makeTestWeapon('Long Sword', 12);
  $armor = makeTestArmor('Leather Vest', 4);
  $party->inventory->addItems($weakWeapon, $strongWeapon, $armor);

  $character->optimizeEquipment($party->inventory, $party);

  $weaponSlot = $character->equipment[0];
  expect($weaponSlot->equipment)->toBe($strongWeapon);

  $armorSlots = array_filter(
    $character->equipment,
    fn($slot) => $slot->acceptsType === Armor::class && $slot->equipment !== null
  );
  expect(array_map(fn($slot) => $slot->equipment, $armorSlots))->toHaveCount(1);
});

it('does not optimize with equipment already worn by another party member', function () {
  $party = new Party();
  $first = new Character('One', 0, new Stats());
  $second = new Character('Two', 0, new Stats());
  $party->addMember($first);
  $party->addMember($second);

  $strongWeapon = makeTestWeapon('Long Sword', 12);
  $weakWeapon = makeTestWeapon('Wooden Sword', 1);
  $party->inventory->addItems($strongWeapon, $weakWeapon);

  $firstWeaponSlot = $first->equipment[0];
  $firstWeaponSlot->equipment = $strongWeapon;
  $second->optimizeEquipment($party->inventory, $party);

  expect($second->equipment[0]->equipment)->toBe($weakWeapon)
    ->and($first->equipment[0]->equipment)->toBe($strongWeapon);
});

it('does not assign a single armor copy to more than one slot during optimization', function () {
  $party = new Party();
  $character = new Character('One', 0, new Stats());
  $party->addMember($character);

  $armor = makeTestArmor('Leather Vest', 4);
  $party->inventory->addItems($armor);

  $character->optimizeEquipment($party->inventory, $party);

  $armorSlots = array_filter(
    $character->equipment,
    fn($slot) => $slot->acceptsType === Armor::class && $slot->equipment !== null
  );
  expect($armorSlots)->toHaveCount(1);
});

it('reclaims its own equipped gear when re-optimizing', function () {
  $party = new Party();
  $character = new Character('One', 0, new Stats());
  $party->addMember($character);

  $strongWeapon = makeTestWeapon('Long Sword', 12);
  $party->inventory->addItems($strongWeapon);

  $weaponSlot = $character->equipment[0];
  $weaponSlot->equipment = $strongWeapon;
  $character->optimizeEquipment($party->inventory, $party);

  expect($character->equipment[0]->equipment)->toBe($strongWeapon);
});

it('clears all equipment slots', function () {
  $character = new Character('One', 0, new Stats());
  $weapon = makeTestWeapon('Wooden Sword', 1);
  $weaponSlot = $character->equipment[0];
  $weaponSlot->equipment = $weapon;

  $character->clearEquipment();

  foreach ($character->equipment as $slot) {
    expect($slot->equipment)->toBeNull();
  }
});
