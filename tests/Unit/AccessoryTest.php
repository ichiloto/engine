<?php

use Ichiloto\Engine\Entities\Inventory\Accessory;
use Ichiloto\Engine\Entities\ParameterChanges;

it('keeps parameter changes when built from an array', function () {
  $accessory = Accessory::fromArray([
    'name' => 'Titan Ring',
    'description' => 'A ring humming with earthen strength.',
    'price' => 1200,
    'parameterChanges' => new ParameterChanges(attack: 5, defence: 3, totalHp: 20),
  ]);

  expect($accessory->parameterChanges->attack)->toBe(5)
    ->and($accessory->parameterChanges->defence)->toBe(3)
    ->and($accessory->parameterChanges->totalHp)->toBe(20)
    ->and($accessory->rating)->toBe(28);
});

it('defaults to neutral parameter changes when none are supplied', function () {
  $accessory = Accessory::fromArray([
    'name' => 'Plain Band',
    'description' => 'A simple band with no enchantment.',
  ]);

  expect($accessory->rating)->toBe(0);
});
