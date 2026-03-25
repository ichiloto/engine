<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;

it('marks a party as defeated when all battlers are knocked out', function () {
  $party = new Party();
  $party->addMember(new Character('One', 0, new Stats(currentHp: 0, currentMp: 0)));
  $party->addMember(new Character('Two', 0, new Stats(currentHp: 0, currentMp: 0)));

  expect($party->isDefeated())->toBeTrue();
});

it('does not mark a party as defeated while a battler is still conscious', function () {
  $party = new Party();
  $party->addMember(new Character('One', 0, new Stats(currentHp: 0, currentMp: 0)));
  $party->addMember(new Character('Two', 0, new Stats(currentHp: 10, currentMp: 0)));

  expect($party->isDefeated())->toBeFalse();
});

it('tracks remaining equipment copies after party members equip them', function () {
  $party = new Party();
  $weapon = Weapon::fromArray([
    'name' => 'Wooden Sword',
    'description' => 'A simple sword.',
    'icon' => '/',
    'quantity' => 4,
    'parameterChanges' => new ParameterChanges(attack: 2),
  ]);
  $firstCharacter = new Character('One', 0, new Stats());
  $secondCharacter = new Character('Two', 0, new Stats());

  $party->addMember($firstCharacter);
  $party->addMember($secondCharacter);
  $party->inventory->addItems($weapon);

  $firstWeaponSlot = $firstCharacter->equipment[0];
  $firstWeaponSlot->equipment = $weapon;
  expect($party->getAvailableEquipmentQuantity($weapon))->toBe(3);

  $secondWeaponSlot = $secondCharacter->equipment[0];
  $secondWeaponSlot->equipment = $weapon;
  expect($party->getAvailableEquipmentQuantity($weapon))->toBe(2);
});
