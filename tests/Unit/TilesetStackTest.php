<?php

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;

/** A synthetic tileset: a three-layer stack and two decorations drawn on it, a 2x2 crag with a one-cell flank each side and a 1x1 rock. */
function stackedTileset(array $overrides = []): array
{
  return array_replace_recursive([
    'name' => 'Synthetic world',
    'sheets' => ['B' => 'Graphics/Tilesets/B.png'],
    'stacks' => ['relief' => ['layers' => ['relief-a', 'relief-b', 'relief-c']]],
    'pieces' => [
      'crag' => ['name' => 'Crag', 'layer' => 'terrain', 'glyphs' => ['    ', '    '], 'tiles' => ['relief-a' => ['1 2 3 4', '5 6 7 8']]],
      'rock' => ['name' => 'Rock', 'layer' => 'terrain', 'glyphs' => [' '], 'tiles' => ['relief-a' => ['9']]],
      'wall' => ['name' => 'Wall', 'layer' => 'buildings', 'glyphs' => ['#'], 'tiles' => ['objects' => ['20']]],
    ],
  ], $overrides);
}

it('lays a stack\'s tiles in each cell by ground row, then column, whatever order they arrive in', function () {
  $stack = Tileset::fromArray('synthetic', stackedTileset())->stacks['relief'];
  // The crag stamped with its top-left at (0, 0) covers (0..3, 0..1), ground row 1. The rock at (1, 1) has ground row 1 too,
  // centred further west, so the crag draws in front of it; a rock at (1, 2) would sit further south, in front.
  expect($stack->layTile([6], 9, 1, 1))->toBe([9, 6])
    ->and($stack->layTile([9], 6, 1, 1))->toBe([9, 6])
    ->and($stack->layTile([2], 9, 1, 0))->toBe([9, 2])
    ->and($stack->layTile([], 9, 5, 5))->toBe([9])
    // A tile the cell already holds is not laid twice; tiles no stacked piece draws stay at the bottom.
    ->and($stack->layTile([9], 9, 1, 1))->toBe([9])
    ->and($stack->layTile([77, 6], 9, 1, 1))->toBe([77, 9, 6])
    ->and($stack->locate(7))->toMatchArray(['column' => 2, 'row' => 1])
    ->and($stack->locate(20))->toBeNull();
  // The crag stamped one row lower, its top row on (1, 1), stands further south than the rock there and draws in front.
  expect($stack->layTile([2], 9, 1, 1))->toBe([9, 2]);
});

it('refuses a cell holding more tiles than the stack has layers', function () {
  $stack = Tileset::fromArray('synthetic', stackedTileset())->stacks['relief'];
  expect(fn() => $stack->layTile([1, 5, 77], 9, 0, 0))->toThrow(InvalidArgumentException::class, 'has 3 layers')
    ->and(fn() => $stack->layTile([], 20, 0, 0))->toThrow(InvalidArgumentException::class, 'none of stack relief');
});

it('gives a stacked piece every layer of its stack, owned by the piece\'s gameplay layer', function () {
  $tileset = Tileset::fromArray('synthetic', stackedTileset());
  $names = ['objects', 'relief-a', 'relief-b', 'relief-c'];
  expect($tileset->getStackOf($tileset->pieces['crag'])?->id)->toBe('relief')
    ->and($tileset->getStackOf($tileset->pieces['wall']))->toBeNull()
    ->and($tileset->getPieceTileLayers($tileset->pieces['rock']))->toBe(['relief-a', 'relief-b', 'relief-c'])
    ->and(MapGraphics::resolveLayerOwners(null, $names, ['terrain', 'buildings'], $tileset, 'synthetic'))->toBe([
      'objects' => 'buildings', 'relief-a' => 'terrain', 'relief-b' => 'terrain', 'relief-c' => 'terrain',
    ]);
});

it('refuses stacks and stacked pieces that could change play or lose track of their objects', function (array $overrides) {
  expect(fn() => Tileset::fromArray('synthetic', stackedTileset($overrides)))->toThrow(InvalidArgumentException::class);
})->with([
  'unnamed layer' => [['stacks' => ['relief' => ['layers' => [1 => null]]]]],
  'one layer' => [['stacks' => ['solo' => ['layers' => ['solo-a']]]]],
  'unknown key' => [['stacks' => ['relief' => ['order' => 'rows']]]],
  'bad stack id' => [['stacks' => ['Relief!' => ['layers' => ['x-a', 'x-b']]]]],
  'layer twice' => [['stacks' => ['relief' => ['layers' => ['relief-a', 'relief-b', 'relief-a']]]]],
  'kept layer' => [['pieces' => ['rock' => ['keeps' => ['objects']]]]],
  'upper layer' => [['pieces' => ['rock' => ['tiles' => ['relief-b' => ['9']]]]]],
  'second layer' => [['pieces' => ['rock' => ['tiles' => ['relief-a' => ['9'], 'objects' => ['21']]]]]],
  'shared tile' => [['pieces' => ['rock' => ['tiles' => ['relief-a' => ['4']]]]]],
]);

it('takes a stacked obstacle\'s ground footprint, whose stack then belongs to no single gameplay layer', function () {
  // A mound standing on walkable ground: its middle cell blocks, on the fixtures layer above the terrain.
  $tileset = Tileset::fromArray('synthetic', stackedTileset(['pieces' => [
    'mound' => ['name' => 'Mound', 'layer' => 'fixtures', 'glyphs' => [' n '], 'tiles' => ['relief-a' => ['30 31 32']],
      'occupancy' => [[null, CollisionType::SOLID, null]]],
  ]]));
  $mound = $tileset->pieces['mound'];
  expect($tileset->getStackOf($mound)?->id)->toBe('relief')
    ->and($mound->occupancy)->toBe([[null, CollisionType::SOLID, null]])
    ->and($tileset->stacks['relief']->locate(31))->toMatchArray(['piece' => $mound, 'column' => 1, 'row' => 0])
    // Its tiles belong to the fixtures layer and the crag's and rock's to the terrain, so the stack's layers cover every glyph.
    ->and(MapGraphics::resolveLayerOwners(null, ['relief-a', 'relief-b'], ['terrain', 'fixtures'], $tileset, 'synthetic'))
    ->toBe(['relief-a' => null, 'relief-b' => null]);
});

it('refuses a stacked footprint\'s glyphs on the layer terrain kinds are painted on', function () {
  expect(fn() => Tileset::fromArray('synthetic', stackedTileset([
    'sheets' => ['A2' => 'Graphics/Tilesets/A2.png'],
    'kinds' => ['grass' => ['name' => 'Grass', 'layer' => 'terrain', 'glyph' => '<fg=green>.</>',
      'art' => ['layer' => 'ground', 'tile' => 2816], 'passage' => 'walk', 'atlas' => 'ground']],
    'pieces' => ['rock' => ['glyphs' => ['^']]],
  ])))->toThrow(InvalidArgumentException::class, 'where they would replace the kind it stands on');
});

it('refuses a stack on a layer terrain kinds paint', function () {
  expect(fn() => Tileset::fromArray('synthetic', stackedTileset([
    'sheets' => ['A2' => 'Graphics/Tilesets/A2.png'],
    'kinds' => ['grass' => ['name' => 'Grass', 'layer' => 'terrain', 'glyph' => '<fg=green>.</>',
      'art' => ['layer' => 'relief-b', 'tile' => 2816], 'passage' => 'walk', 'atlas' => 'ground']],
  ])))->toThrow(InvalidArgumentException::class, 'terrain kinds paint tile layer relief-b');
});
