<?php

declare(strict_types=1);

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Stats\StatKey;
use Ichiloto\Engine\Entities\Stats\PermanentStatModifier;

it('previews the real capped stat result without changing gear resources growth or item quantities', function () {
  $actor = new Character('Tester', 0, new Stats(currentHp: 80, totalHp: 100), actorNaturalAdjustments: ['attack' => 9990]);
  $actor->grantPermanentGrowth(new PermanentStatModifier('reward', StatKey::ATTACK, 5, 'reward', 'fixture'));
  $old = new Weapon('Old', '', '/', 1, 3, parameterChanges: new ParameterChanges(attack: 20, speed: 2), id: 'old');
  $candidate = new Weapon('Candidate', '', '/', 1, 2, parameterChanges: new ParameterChanges(attack: 2, totalHp: 15, totalMp: 3, defence: 4, magicAttack: 5, magicDefence: 6, speed: -1, grace: 7, evasion: 8), id: 'candidate');
  $slot = $actor->equipment[0]->name;
  $actor->assignEquipment($slot, $old);
  $before = serialize([$actor, $old, $candidate]);
  $preview = $actor->previewEquipmentStats($slot, $candidate);
  expect($preview)->toBeInstanceOf(Stats::class)
    ->and(serialize([$actor, $old, $candidate]))->toBe($before)
    ->and($actor->equipment[0]->equipment)->toBe($old);
  $actor->assignEquipment($slot, $candidate);
  foreach (StatKey::cases() as $stat) {
    $property = $stat->statsProperty();
    expect($preview->$property)->toBe($actor->effectiveStats->$property);
  }
  $before = serialize($actor);
  $empty = $actor->previewEquipmentStats($slot, null);
  expect(serialize($actor))->toBe($before);
  $actor->assignEquipment($slot, null);
  foreach (StatKey::cases() as $stat) {
    $property = $stat->statsProperty();
    expect($empty->$property)->toBe($actor->effectiveStats->$property);
  }
});

it('refuses role slot and semantic mismatches while previewing without side effects', function () {
  $actor = new Character('Caster', 0, new Stats());
  $actor->role = new CharacterRole($actor, 'Caster', allowedWeaponTypes: [WeaponType::STAFF]);
  $sword = new Weapon('Sword', '', '/', 1, 1, equipmentType: WeaponType::SWORD);
  $staff = new Weapon('Staff', '', '/', 1, 1, equipmentType: WeaponType::STAFF);
  $before = serialize($actor);
  expect($actor->previewEquipmentStats($actor->equipment[0]->name, $sword))->toBeNull()
    ->and($actor->previewEquipmentStats('missing', $staff))->toBeNull()
    ->and($actor->previewEquipmentStats($actor->equipment[0]->name, new Armor('Body', '', '/', 1, 1)))->toBeNull()
    ->and($actor->previewEquipmentStats($actor->equipment[0]->name, $staff))->toBeInstanceOf(Stats::class)
    ->and(serialize($actor))->toBe($before);
});


it('previews one compatible accessory slot while preserving another and rejects same-class semantic mismatches', function () {
  $first = new \Ichiloto\Engine\Entities\EquipmentSlot('Left charm', '', '/', \Ichiloto\Engine\Entities\Inventory\Accessory::class,
    \Ichiloto\Engine\Entities\Inventory\EquipmentSlotType::ACCESSORY);
  $second = new \Ichiloto\Engine\Entities\EquipmentSlot('Right charm', '', '/', \Ichiloto\Engine\Entities\Inventory\Accessory::class,
    \Ichiloto\Engine\Entities\Inventory\EquipmentSlotType::ACCESSORY);
  $actor = new Character('Tester', 0, new Stats(), equipment: [$first, $second]);
  $old = new \Ichiloto\Engine\Entities\Inventory\Accessory('Left', '', '/', 1, parameterChanges: new ParameterChanges(attack: 2));
  $other = new \Ichiloto\Engine\Entities\Inventory\Accessory('Right', '', '/', 1, parameterChanges: new ParameterChanges(attack: 8));
  $candidate = new \Ichiloto\Engine\Entities\Inventory\Accessory('Candidate', '', '/', 1, parameterChanges: new ParameterChanges(attack: 5));
  $actor->assignEquipment('Left charm', $old);
  $actor->assignEquipment('Right charm', $other);
  $before = serialize($actor);
  $left = $actor->previewEquipmentStats('Left charm', $candidate);
  $right = $actor->previewEquipmentStats('Right charm', $candidate);
  expect($left->attack - $actor->effectiveStats->attack)->toBe(3)
    ->and($right->attack - $actor->effectiveStats->attack)->toBe(-3)
    ->and($second->equipment)->toBe($other)->and(serialize($actor))->toBe($before);
  $party = new \Ichiloto\Engine\Entities\Party();
  $party->addMember($actor);
  $comparison = \Ichiloto\Engine\UI\Presentation\ShopEquipmentComparison::create($party, $candidate);
  expect($comparison->source)->toContain('Tester / Left charm: Can equip', 'Tester / Right charm: Can equip', '+3', '−3');
  foreach ($comparison->getLines(12) as $line) {
    expect(\Ichiloto\Engine\UI\Text\TextViewport::measure(\Ichiloto\Engine\UI\Presentation\ShopEquipmentComparison::getPlainLine($line)))->toBeLessThanOrEqual(12);
  }
  $ordinary = new Character('Armor tester', 0, new Stats());
  $head = new Armor('Head', '', '/', 1, semanticSlot: \Ichiloto\Engine\Entities\Inventory\EquipmentSlotType::HEAD);
  expect($ordinary->previewEquipmentStats('Body', $head))->toBeNull()
    ->and($ordinary->previewEquipmentStats('Head', $head))->toBeInstanceOf(Stats::class);
});
