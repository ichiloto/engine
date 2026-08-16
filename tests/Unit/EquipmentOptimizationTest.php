<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\EquipmentOptimization\DeclaredEquipmentOptimizationPolicy;
use Ichiloto\Engine\Entities\EquipmentOptimization\EquipmentOptimizationPolicyRegistry;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Roles\CharacterRole;

afterEach(function () {
  EquipmentOptimizationPolicyRegistry::reset();
});

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

function assignOptimizationTestRole(Character $character, string $roleName): void
{
  $property = new ReflectionProperty(Character::class, 'role');
  $property->setValue($character, new CharacterRole($character, $roleName));
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

it('selects the same sidegrades differently under declared role policies', function () {
  $party = new Party();
  $character = new Character('One', 0, new Stats());
  $party->addMember($character);
  $physical = new Weapon(
    'Physical Sidegrade',
    '',
    '/',
    10,
    parameterChanges: new ParameterChanges(attack: 4),
    id: 'equipment.physical-sidegrade',
  );
  $arcane = new Weapon(
    'Arcane Sidegrade',
    '',
    '/',
    10,
    parameterChanges: new ParameterChanges(magicAttack: 4),
    id: 'equipment.arcane-sidegrade',
  );
  $party->inventory->addItems($physical, $arcane);
  $policy = new DeclaredEquipmentOptimizationPolicy(
    roleStatWeights: [
      'Striker' => ['attack' => 3, 'magicAttack' => 0],
      'Mystic' => ['attack' => 0, 'magicAttack' => 3],
    ],
  );

  assignOptimizationTestRole($character, 'Striker');
  $character->optimizeEquipment($party->inventory, $party, $policy);
  expect($character->equipment[0]->equipment)->toBe($physical);

  assignOptimizationTestRole($character, 'Mystic');
  $character->optimizeEquipment($party->inventory, $party, $policy);
  expect($character->equipment[0]->equipment)->toBe($arcane);
});

it('lets semantic-slot policy override base stat priorities', function () {
  $party = new Party();
  $character = new Character('One', 0, new Stats());
  $party->addMember($character);
  $physical = new Armor(
    'Physical Coat',
    '',
    '#',
    10,
    parameterChanges: new ParameterChanges(defence: 4),
    id: 'equipment.physical-coat',
  );
  $arcane = new Armor(
    'Arcane Coat',
    '',
    '#',
    10,
    parameterChanges: new ParameterChanges(magicDefence: 4),
    id: 'equipment.arcane-coat',
  );
  $party->inventory->addItems($physical, $arcane);
  $policy = new DeclaredEquipmentOptimizationPolicy(
    statWeights: ['defence' => 2, 'magicDefence' => 0],
    slotStatWeights: ['body' => ['defence' => 0, 'magicDefence' => 3]],
  );

  $character->optimizeEquipment($party->inventory, $party, $policy);

  expect($character->equipment[3]->equipment)->toBe($arcane);
});

it('scores bounded accuracy critical elemental and typed-special outcomes only when declared', function () {
  $character = new Character('One', 0, new Stats());
  $equipment = new Weapon(
    'Policy Blade',
    '',
    '/',
    10,
    parameterChanges: new ParameterChanges(attack: 2),
    element: 'Fire',
    id: 'equipment.policy-blade',
    accuracyModifier: 3,
    criticalModifier: 2,
    specialProperty: ['type' => 'counter'],
  );
  $policy = new DeclaredEquipmentOptimizationPolicy(
    statWeights: ['attack' => 4, 'accuracy' => 2, 'critical' => 3],
    elementOutcomeWeights: ['offence:fire' => 7],
    specialPropertyWeights: ['counter' => 9],
  );
  $score = $policy->score($character, $character->equipment[0], $equipment);

  expect($score?->value)->toBe(36)
    ->and($score?->components)->toBe([
      'attack' => 8,
      'accuracy' => 6,
      'critical' => 6,
      'element:offence:fire' => 7,
      'special:counter' => 9,
    ]);
});

it('excludes project-controlled equipment from automatic selection', function () {
  $party = new Party();
  $character = new Character('One', 0, new Stats());
  $party->addMember($character);
  $ordinary = new Weapon(
    'Ordinary Blade',
    '',
    '/',
    10,
    parameterChanges: new ParameterChanges(attack: 2),
    id: 'equipment.ordinary-blade',
  );
  $unique = new Weapon(
    'Unique Blade',
    '',
    '/',
    10,
    parameterChanges: new ParameterChanges(attack: 20),
    id: 'equipment.unique-blade',
    acquisitionPolicy: 'unique',
  );
  $party->inventory->addItems($ordinary, $unique);
  $policy = new DeclaredEquipmentOptimizationPolicy(
    statWeights: ['attack' => 1],
    excludedAcquisitionPolicies: ['unique'],
  );

  $character->optimizeEquipment($party->inventory, $party, $policy);

  expect($character->equipment[0]->equipment)->toBe($ordinary);
});

it('breaks declared-policy ties by stable definition id', function () {
  $party = new Party();
  $character = new Character('One', 0, new Stats());
  $party->addMember($character);
  $later = new Weapon('Later', '', '/', 10, id: 'equipment.z-later');
  $earlier = new Weapon('Earlier', '', '/', 10, id: 'equipment.a-earlier');
  $party->inventory->addItems($later, $earlier);

  $character->optimizeEquipment(
    $party->inventory,
    $party,
    new DeclaredEquipmentOptimizationPolicy(),
  );

  expect($character->equipment[0]->equipment)->toBe($earlier);
});

it('preserves role equipment restrictions while optimizing', function () {
  $party = new Party();
  $character = new Character('One', 0, new Stats());
  $party->addMember($character);
  $property = new ReflectionProperty(Character::class, 'role');
  $property->setValue($character, new CharacterRole(
    $character,
    'Restricted',
    allowedWeaponTypes: [WeaponType::SWORD],
  ));
  $sword = new Weapon(
    'Allowed Sword',
    '',
    '/',
    10,
    parameterChanges: new ParameterChanges(attack: 2),
    equipmentType: WeaponType::SWORD,
    id: 'equipment.allowed-sword',
  );
  $axe = new Weapon(
    'Forbidden Axe',
    '',
    '/',
    10,
    parameterChanges: new ParameterChanges(attack: 20),
    equipmentType: WeaponType::AXE,
    id: 'equipment.forbidden-axe',
  );
  $party->inventory->addItems($sword, $axe);

  $character->optimizeEquipment(
    $party->inventory,
    $party,
    new DeclaredEquipmentOptimizationPolicy(statWeights: ['attack' => 1]),
  );

  expect($character->equipment[0]->equipment)->toBe($sword);
});
