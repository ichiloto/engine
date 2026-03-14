<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Field\PlayerSpriteSet;

it('normalizes configured directional sprites from scalar values', function () {
  $spriteSet = PlayerSpriteSet::fromArray([
    'sprites' => [
      'north' => '🧍🏽 ',
      'east' => '🚶🏽‍➡️',
      'south' => '🧍🏽',
      'west' => '🚶🏽‍',
    ],
  ]);

  expect($spriteSet->toArray())->toBe([
    'north' => ['🧍🏽 '],
    'east' => ['🚶🏽‍➡️'],
    'south' => ['🧍🏽'],
    'west' => ['🚶🏽‍'],
  ]);
});

it('resolves headings from configured sprites', function () {
  $spriteSet = new PlayerSpriteSet(
    north: ['🧍🏽 '],
    east: ['🚶🏽‍➡️'],
    south: ['🧍🏽'],
    west: ['🚶🏽‍'],
  );

  expect($spriteSet->resolveHeading(['🧍🏽 ']))->toBe(MovementHeading::NORTH)
    ->and($spriteSet->resolveHeading(['🚶🏽‍➡️']))->toBe(MovementHeading::EAST)
    ->and($spriteSet->resolveHeading(['🧍🏽']))->toBe(MovementHeading::SOUTH)
    ->and($spriteSet->resolveHeading(['🚶🏽‍']))->toBe(MovementHeading::WEST);
});

it('returns the configured sprite rows for each heading', function () {
  $spriteSet = new PlayerSpriteSet(
    north: ['north'],
    east: ['east'],
    south: ['south'],
    west: ['west'],
  );

  expect($spriteSet->getSpriteForHeading(MovementHeading::NORTH))->toBe(['north'])
    ->and($spriteSet->getSpriteForHeading(MovementHeading::EAST))->toBe(['east'])
    ->and($spriteSet->getSpriteForHeading(MovementHeading::SOUTH))->toBe(['south'])
    ->and($spriteSet->getSpriteForHeading(MovementHeading::WEST))->toBe(['west'])
    ->and($spriteSet->getSpriteForHeading(MovementHeading::NONE))->toBe(['south']);
});
