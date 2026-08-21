<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Entities\Stats;

function makeEquipCharacter(): Character
{
  return new Character('Tester', 0, new Stats(currentHp: 100, totalHp: 100));
}

function makeWeapon(?WeaponType $type): Weapon
{
  return new Weapon('Blade', 'A blade.', '/', 10, 1, parameterChanges: new ParameterChanges(attack: 3), equipmentType: $type);
}

it('resolves authored equipment type names', function () {
  expect(Equipment::resolveEquipmentType('Sword', isWeapon: true))->toBe(WeaponType::SWORD)
    ->and(Equipment::resolveEquipmentType('Heavy Armor', isWeapon: false))->toBe(ArmorType::HEAVY_ARMOR)
    ->and(Equipment::resolveEquipmentType(WeaponType::BOW, isWeapon: true))->toBe(WeaponType::BOW)
    ->and(Equipment::resolveEquipmentType('Trebuchet', isWeapon: true))->toBeNull()
    ->and(Equipment::resolveEquipmentType('', isWeapon: true))->toBeNull()
    ->and(Equipment::resolveEquipmentType(null, isWeapon: false))->toBeNull();
});

it('allows every type when a role lists none', function () {
  $character = makeEquipCharacter();
  $role = new CharacterRole($character, 'Freeform');

  expect($role->allowsEquipmentType(WeaponType::AXE))->toBeTrue()
    ->and($role->allowsEquipmentType(ArmorType::HEAVY_ARMOR))->toBeTrue()
    ->and($role->allowsEquipmentType(null))->toBeTrue();
});

it('restricts a role to its listed types', function () {
  $character = makeEquipCharacter();
  $role = new CharacterRole(
    $character,
    'Oracle',
    allowedWeaponTypes: [WeaponType::STAFF],
    allowedArmorTypes: [ArmorType::MAGIC_ARMOR],
  );

  expect($role->allowsEquipmentType(WeaponType::STAFF))->toBeTrue()
    ->and($role->allowsEquipmentType(WeaponType::SWORD))->toBeFalse()
    ->and($role->allowsEquipmentType(ArmorType::MAGIC_ARMOR))->toBeTrue()
    ->and($role->allowsEquipmentType(ArmorType::HEAVY_ARMOR))->toBeFalse()
    // An untyped item is never restricted, so old data keeps working.
    ->and($role->allowsEquipmentType(null))->toBeTrue();
});

it('gates canEquip on the role restriction', function () {
  $character = makeEquipCharacter();
  $character->role = new CharacterRole($character, 'Oracle', allowedWeaponTypes: [WeaponType::STAFF]);

  expect($character->canEquip(makeWeapon(WeaponType::SWORD)))->toBeFalse()
    ->and($character->canEquip(makeWeapon(WeaponType::STAFF)))->toBeTrue()
    ->and($character->canEquip(makeWeapon(null)))->toBeTrue();
});

it('carries the type through armor hydration', function () {
  $armor = Armor::fromArray([
    'name' => 'Iron Plate',
    'description' => 'Heavy protection.',
    'type' => 'Heavy Armor',
  ]);

  expect($armor->equipmentType)->toBe(ArmorType::HEAVY_ARMOR);
});
