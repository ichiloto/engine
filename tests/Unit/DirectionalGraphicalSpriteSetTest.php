<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Sprites\DirectionalGraphicalSpriteSet;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use function Tests\Support\Rendering\graphicalSpriteData;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

it('parses the documented complete set and resolves the existing gameplay headings', function ($heading, $direction) {
  $data = graphicalSpriteData();
  $set = DirectionalGraphicalSpriteSet::fromArray($data);
  expect($set->getForHeading($heading))->toBe($set->$direction)
    ->and($set->$direction->asset)->toBe($data[$direction]['asset'])
    ->and($set->$direction->width)->toBe(32)->and($set->$direction->height)->toBe(48)
    ->and($set->$direction->anchor)->toBe(PresentationSpriteAnchor::BOTTOM_CENTER)
    ->and($set->$direction->layer)->toBe(100);
})->with([
  [MovementHeading::NORTH, 'north'], [MovementHeading::EAST, 'east'],
  [MovementHeading::SOUTH, 'south'], [MovementHeading::WEST, 'west'], [MovementHeading::NONE, 'south'],
]);

it('keeps each direction independent and detaches parsed data from caller references', function () {
  $data = graphicalSpriteData();
  $width = 64;
  $data['north']['width'] =& $width;
  $data['east']['height'] = 72;
  $data['west']['layer'] = -5;
  $set = DirectionalGraphicalSpriteSet::fromArray($data);
  $width = 128;
  $data['south']['asset'] = 'Changed.png';
  expect($set->north->width)->toBe(64)->and($set->south->width)->toBe(32)
    ->and($set->east->height)->toBe(72)->and($set->south->height)->toBe(48)
    ->and($set->west->layer)->toBe(-5)->and($set->south->layer)->toBe(100)
    ->and($set->south->asset)->toEndWith('South.png');
  expect(function () use ($set) { $set->north = $set->south; })->toThrow(Error::class);
});

it('supports direct typed construction and defaults only omitted anchor and layer', function () {
  $definition = new GraphicalSpriteDefinition('Hero.png', 32, 48);
  $direct = new DirectionalGraphicalSpriteSet($definition, $definition, $definition, $definition);
  $parsed = DirectionalGraphicalSpriteSet::fromArray(array_fill_keys(
    ['north', 'east', 'south', 'west'], ['asset' => 'Hero.png', 'width' => 32, 'height' => 48],
  ));
  expect($direct)->toEqual($parsed);
});

it('rejects every missing cardinal direction rather than silently falling back', function ($direction) {
  $data = graphicalSpriteData();
  unset($data[$direction]);
  expect(fn() => DirectionalGraphicalSpriteSet::fromArray($data))
    ->toThrow(InvalidArgumentException::class, "direction '$direction'");
})->with(['north', 'east', 'south', 'west']);

it('rejects malformed direction entries with their direction in the diagnostic', function ($entry) {
  $data = graphicalSpriteData();
  $data['east'] = $entry;
  expect(fn() => DirectionalGraphicalSpriteSet::fromArray($data))
    ->toThrow(InvalidArgumentException::class, "direction 'east'");
})->with([null, false, 'East.png', 123, [[]], [['asset' => 'East.png']]]);

it('validates authored types values and field names without coercion', function ($field, $value) {
  $data = graphicalSpriteData();
  $data['west'][$field] = $value;
  expect(fn() => DirectionalGraphicalSpriteSet::fromArray($data))
    ->toThrow(InvalidArgumentException::class, "direction 'west'");
})->with([
  ['asset', 123], ['asset', null], ['asset', '../West.png'], ['asset', "bad\xFF"],
  ['width', '32'], ['width', 32.0], ['width', null], ['width', 0], ['height', false], ['height', 4097],
  ['anchor', null], ['anchor', PresentationSpriteAnchor::BOTTOM_CENTER], ['anchor', 'top_left'],
  ['layer', '100'], ['layer', null], ['layer', 2147483648], ['layer', -2147483649], ['widht', 32],
]);

it('rejects unrecognized directions instead of ignoring configuration typos', function () {
  $data = graphicalSpriteData();
  $data['North'] = $data['north'];
  expect(fn() => DirectionalGraphicalSpriteSet::fromArray($data))->toThrow(InvalidArgumentException::class, 'only accepts');
});
