<?php

declare(strict_types=1);

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use Ichiloto\Engine\Rendering\Tilesets\TilesetSheet;

use function Tests\Support\Rendering\writeTestPng;

require_once dirname(__DIR__) . '/Support/Rendering/GraphicalSpriteFixtures.php';

function readOccupancyRecipePiece(array $extra = [], bool $connected = false): TilesetPiece
{
  $data = ['name' => 'Synthetic object', 'layer' => 'fixtures', 'glyphs' => ['xx', 'xx']];
  if ($connected) {
    $data['connects'] = TilesetPiece::LINES;
    $data['glyphs'] = ['horizontal' => '<info>-</info>', 'vertical' => '|', 'corner' => '+'];
  }
  return TilesetPiece::fromArray('synthetic', $extra + $data, 'Synthetic tileset');
}

it('retains rectangular occupancy masks with null cells distinct from explicit walkable cells', function (array $mask) {
  $data = ['name' => 'Synthetic object', 'layer' => 'fixtures', 'glyphs' => ['<info> x</info>', 'x '],
    'tiles' => ['objects' => ['12 0', '0 13']], 'keeps' => ['floor'], 'occupancy' => $mask];
  $original = $data;
  $piece = TilesetPiece::fromArray('synthetic', $data, 'Synthetic tileset');
  $direct = new TilesetPiece('synthetic', 'Synthetic object', 'fixtures', [['<info> </info>', '<info>x</info>'], ['x', ' ']],
    ['objects' => [['12', '0'], ['0', '13']]], null, [], [], null, $data['glyphs'], null, ['floor'], $mask);
  expect($piece->occupancy)->toBe($mask)
    ->and($direct->occupancy)->toBe($mask)
    ->and([$piece->width, $piece->height])->toBe([2, 2])
    ->and($direct->grid)->toBe($piece->grid)
    ->and($piece->glyphs)->toBe([[' ', 'x'], ['x', ' ']])
    ->and($direct->tiles)->toBe($piece->tiles)
    ->and($direct->keeps)->toBe($piece->keeps)
    ->and($direct->getSourceGrid())->toBe($piece->getSourceGrid())
    ->and($data)->toBe($original);
})->with([
  'overhang and ground' => [[[null, CollisionType::NONE], [CollisionType::SOLID, CollisionType::COUNTER]]],
  'entirely unchanged' => [[[null, null], [null, null]]],
  'entirely walkable' => [[[CollisionType::NONE, CollisionType::NONE], [CollisionType::NONE, CollisionType::NONE]]],
]);

it('matches non square masks to logical rows and columns without reshaping them', function (array $glyphs, array $mask, array $geometry) {
  $piece = readOccupancyRecipePiece(['glyphs' => $glyphs, 'occupancy' => $mask]);
  $direct = new TilesetPiece('synthetic', 'Synthetic object', 'fixtures', array_map(str_split(...), $glyphs), occupancy: $mask);
  expect([$piece->width, $piece->height])->toBe($geometry)
    ->and($piece->occupancy)->toBe($mask)
    ->and($direct->occupancy)->toBe($mask);
})->with([
  'wide' => [['xxx', 'xxx'], [[null, CollisionType::SOLID, null], [CollisionType::NONE, null, CollisionType::COUNTER]], [3, 2]],
  'tall' => [['x', 'x', 'x'], [[null], [CollisionType::SOLID], [CollisionType::NONE]], [1, 3]],
  'single row' => [['xxx'], [[null, CollisionType::SOLID, CollisionType::NONE]], [3, 1]],
]);

it('accepts every final CollisionType in normal and connected recipes without coercion', function (CollisionType $type) {
  $mask = [[$type]];
  $normal = readOccupancyRecipePiece(['glyphs' => [' '], 'occupancy' => $mask]);
  $connected = readOccupancyRecipePiece(['occupancy' => $mask], true);
  $direct = new TilesetPiece('synthetic', 'Synthetic object', 'fixtures', [[' ']], occupancy: $mask);
  $directConnected = new TilesetPiece('synthetic', 'Synthetic object', 'fixtures', [['+']], connects: TilesetPiece::LINES,
    shapes: ['horizontal' => '-', 'vertical' => '|', 'corner' => '+'], occupancy: $mask);
  expect($normal->occupancy)->toBe($mask)
    ->and($connected->occupancy)->toBe($mask)
    ->and($direct->occupancy)->toBe($mask)
    ->and($directConnected->occupancy)->toBe($mask);
})->with(function () {
  foreach (CollisionType::cases() as $type) {
    if ($type !== CollisionType::PASS_THROUGH) { yield $type->name => [$type]; }
  }
});

it('leaves absent occupancy without a glyph or tile derived recipe and preserves old constructor arguments', function () {
  $plain = readOccupancyRecipePiece(['glyphs' => ['# '], 'tiles' => ['objects' => ['0 12']],
    'effect' => 'synthetic-effect', 'keeps' => ['floor']]);
  $direct = new TilesetPiece('synthetic', 'Synthetic object', 'fixtures', [['#', ' ']], ['objects' => [['0', '12']]],
    null, [], [], 'synthetic-effect', ['# '], null, ['floor']);
  $connected = readOccupancyRecipePiece(['tiles' => ['objects' => '12']], true);
  $directConnected = new TilesetPiece('synthetic', 'Synthetic object', 'fixtures', [['+']], [], TilesetPiece::LINES,
    ['horizontal' => '<info>-</info>', 'vertical' => '|', 'corner' => '+'],
    ['objects' => array_fill_keys(TilesetPiece::LINE_SHAPES, '12')], null, ['+'],
    ['horizontal' => '<info>-</info>', 'vertical' => '|', 'corner' => '+']);
  expect($plain->occupancy)->toBeNull()
    ->and($direct->occupancy)->toBeNull()
    ->and($connected->occupancy)->toBeNull()
    ->and($directConnected->occupancy)->toBeNull()
    ->and($direct->effect)->toBe($plain->effect)
    ->and($direct->keeps)->toBe($plain->keeps)
    ->and($direct->tiles)->toBe($plain->tiles)
    ->and($direct->getSourceGrid())->toBe($plain->getSourceGrid())
    ->and($directConnected->getSourceShapeGrid())->toBe($connected->getSourceShapeGrid())
    ->and($directConnected->shapeTiles)->toBe($connected->shapeTiles);
});

it('keeps one connected recipe for every shape without changing membership or source', function (mixed $cell) {
  $mask = [[$cell]];
  $piece = readOccupancyRecipePiece(['occupancy' => $mask, 'tiles' => ['objects' => [
    'horizontal' => '12', 'vertical' => '13', 'corner' => '14']]], true);
  expect([$piece->width, $piece->height])->toBe([1, 1])
    ->and($piece->getSourceShapeGrid())->toBe(['horizontal' => '<info>-</info>', 'vertical' => '|', 'corner' => '+'])
    ->and($piece->shapeTiles)->toBe(['objects' => ['horizontal' => '12', 'vertical' => '13', 'corner' => '14']]);
  foreach ([[false, true, false, true], [true, false, true, false], [true, true, false, false], [false, false, false, false]] as $neighbours) {
    $shape = $piece->getLineShape(...$neighbours);
    expect($piece->isMember($piece->shapeGrid[$shape]))->toBeTrue()
      ->and($piece->occupancy)->toBe($mask);
  }
})->with(['ground' => [CollisionType::SOLID], 'unchanged' => [null]]);

it('rejects unsupported occupancy declarations with piece row and cell context', function (mixed $rows, string $reason) {
  expect(fn() => readOccupancyRecipePiece(['occupancy' => $rows]))
    ->toThrow(InvalidArgumentException::class, 'Synthetic tileset piece synthetic occupancy ' . $reason);
  if (is_array($rows)) {
    expect(fn() => new TilesetPiece('synthetic', 'Synthetic object', 'fixtures', [['x', 'x'], ['x', 'x']], occupancy: $rows))
      ->toThrow(InvalidArgumentException::class, 'Piece synthetic occupancy ' . $reason);
  } elseif ($rows !== null) {
    expect(fn() => new TilesetPiece('synthetic', 'Synthetic object', 'fixtures', [['x', 'x'], ['x', 'x']], occupancy: $rows))
      ->toThrow(TypeError::class);
  }
})->with([
  'explicit null' => [null, 'must be a zero-based list of rows'],
  'string declaration' => ['xx', 'must be a zero-based list of rows'],
  'integer declaration' => [1, 'must be a zero-based list of rows'],
  'boolean declaration' => [false, 'must be a zero-based list of rows'],
  'object declaration' => [new stdClass(), 'must be a zero-based list of rows'],
  'wrapper' => [['rows' => []], 'must be a zero-based list of rows'],
  'sparse rows' => [[1 => [null, null], 2 => [null, null]], 'must be a zero-based list of rows'],
  'reordered rows' => [[1 => [null, null], 0 => [null, null]], 'must be a zero-based list of rows'],
  'empty declaration' => [[], 'must have 2 rows'],
  'short height' => [[[null, null]], 'must have 2 rows'],
  'extra height' => [[[null, null], [null, null], [null, null]], 'must have 2 rows'],
  'string row' => [['xx', [null, null]], 'row 0 must be a zero-based list'],
  'null row' => [[null, [null, null]], 'row 0 must be a zero-based list'],
  'scalar row' => [[1, [null, null]], 'row 0 must be a zero-based list'],
  'named cells' => [[['left' => null, 'right' => null], [null, null]], 'row 0 must be a zero-based list'],
  'sparse cells' => [[[0 => null, 2 => null], [null, null]], 'row 0 must be a zero-based list'],
  'empty row' => [[[], [null, null]], 'row 0 must be 2 cells wide'],
  'ragged row' => [[[null], [null, null]], 'row 0 must be 2 cells wide'],
  'wide row' => [[[null, null], [null, null, null]], 'row 1 must be 2 cells wide'],
  'integer cell' => [[[0, null], [null, null]], 'cell 0, 0 must be a CollisionType case or null; got int'],
  'numeric string cell' => [[[null, '1'], [null, null]], 'cell 1, 0 must be a CollisionType case or null; got string'],
  'unknown enum name' => [[[null, null], ['UNKNOWN', null]], 'cell 0, 1 must be a CollisionType case or null; got string'],
  'case name string' => [[[null, null], ['SOLID', null]], 'cell 0, 1 must be a CollisionType case or null; got string'],
  'boolean cell' => [[[null, null], [false, null]], 'cell 0, 1 must be a CollisionType case or null; got bool'],
  'float cell' => [[[null, null], [1.0, null]], 'cell 0, 1 must be a CollisionType case or null; got float'],
  'nested cell' => [[[null, null], [[CollisionType::SOLID], null]], 'cell 0, 1 must be a CollisionType case or null; got array'],
  'foreign enum cell' => [[[null, null], [TilesetSheet::B, null]], 'cell 0, 1 must be a CollisionType case or null; got ' . TilesetSheet::class],
  'pass-through cell' => [[[null, null], [null, CollisionType::PASS_THROUGH]], 'cell 1, 1 must be resolved; PASS_THROUGH'],
]);

it('rejects connected recipes with unsupported shape specific or non single cell geometry', function (mixed $rows, string $reason) {
  expect(fn() => readOccupancyRecipePiece(['occupancy' => $rows], true))
    ->toThrow(InvalidArgumentException::class, 'Synthetic tileset piece synthetic occupancy ' . $reason);
})->with([
  'explicit null' => [null, 'must be a zero-based list of rows'],
  'shape keyed' => [array_fill_keys(TilesetPiece::LINE_SHAPES, [[CollisionType::SOLID]]), 'must be a zero-based list of rows'],
  'empty' => [[], 'must have 1 rows'],
  'two rows' => [[[CollisionType::SOLID], [CollisionType::SOLID]], 'must have 1 rows'],
  'two cells' => [[[CollisionType::SOLID, null]], 'row 0 must be 1 cells wide'],
  'flat cell' => [[CollisionType::SOLID], 'row 0 must be a zero-based list'],
  'pass-through' => [[[CollisionType::PASS_THROUGH]], 'cell 0, 0 must be resolved; PASS_THROUGH'],
]);

it('detaches caller row and cell references and exposes a readonly recipe', function () {
  $type = CollisionType::SOLID;
  $unchanged = null;
  $row = [&$type, &$unchanged];
  $rows = [&$row];
  $parsed = readOccupancyRecipePiece(['glyphs' => ['xx'], 'occupancy' => $rows]);
  $direct = new TilesetPiece('synthetic', 'Synthetic object', 'fixtures', [['x', 'x']], occupancy: $rows);
  $copy = $parsed->occupancy;
  $type = CollisionType::NONE;
  $unchanged = CollisionType::COUNTER;
  $row[] = CollisionType::NPC;
  $copy[0][0] = CollisionType::ITEM;
  expect($parsed->occupancy)->toBe([[CollisionType::SOLID, null]])
    ->and($direct->occupancy)->toBe([[CollisionType::SOLID, null]])
    ->and(fn() => $parsed->occupancy = [])->toThrow(Error::class, 'readonly property');
});

it('keeps the physical recipe independent of glyph style tile identities and replaceable sheet art', function () {
  $root = createTestDirectory('ichiloto-piece-occupancy-art-');
  $mask = [[null, CollisionType::NONE], [CollisionType::SOLID, CollisionType::COUNTER]];
  $data = ['name' => 'Synthetic tileset', 'sheets' => ['B' => 'Graphics/Tilesets/synthetic.png'], 'pieces' => [
    'synthetic' => ['name' => 'Synthetic object', 'layer' => 'fixtures', 'glyphs' => ['##', '##'],
      'tiles' => ['objects' => ['1 0', '2 3']], 'occupancy' => $mask]]];
  $original = $data;
  writeTestPng($root . '/' . $data['sheets']['B'], 32, 32);
  $tileset = Tileset::fromArray('synthetic', $data);
  expect($tileset->getUsableSheets($root)['tileSize'])->toBe(2);
  writeTestPng($root . '/' . $data['sheets']['B'], 64, 64);
  expect($tileset->getUsableSheets($root)['tileSize'])->toBe(4)
    ->and($tileset->pieces['synthetic']->occupancy)->toBe($mask)
    ->and($data)->toBe($original);

  $data['sheets']['B'] = 'Graphics/Tilesets/replaced.png';
  $data['pieces']['synthetic']['glyphs'] = ['<fg=green>  </>', '<info>xy</info>'];
  $data['pieces']['synthetic']['tiles'] = ['objects' => ['0 4', '5 0']];
  writeTestPng($root . '/' . $data['sheets']['B'], 96, 96);
  $replacement = Tileset::fromArray('synthetic', $data);
  expect($replacement->getUsableSheets($root)['tileSize'])->toBe(6)
    ->and($replacement->pieces['synthetic']->occupancy)->toBe($mask)
    ->and([$replacement->pieces['synthetic']->width, $replacement->pieces['synthetic']->height])->toBe([2, 2])
    ->and($replacement->pieces['synthetic']->glyphs)->not->toBe($tileset->pieces['synthetic']->glyphs)
    ->and($replacement->pieces['synthetic']->tiles)->not->toBe($tileset->pieces['synthetic']->tiles);
  unset($data['pieces']['synthetic']['tiles']);
  expect(Tileset::fromArray('synthetic', $data)->pieces['synthetic']->occupancy)->toBe($mask);
});

it('never applies a piece recipe to runtime occupancy from placed glyphs or graphical tiles', function () {
  $data = ['occupancy' => [[CollisionType::COUNTER, CollisionType::NONE], [CollisionType::SAVE_POINT, CollisionType::ENCOUNTER]]];
  $original = $data;
  $mask = [[null, CollisionType::SOLID], [CollisionType::NONE, null]];
  $piece = readOccupancyRecipePiece(['glyphs' => ['# ', '##'], 'tiles' => ['objects' => ['1 2', '3 0']], 'occupancy' => $mask]);
  $layers = new MapLayerSet([new MapLayer('fixtures', 1, false, 'synthetic', implode("\n", array_map(
    static fn(array $row): string => implode('', $row), $piece->glyphs)))]);
  $dictionary = ['#' => CollisionType::SOLID, ' ' => CollisionType::NONE];
  $snapshot = MapCollisionResolver::resolveMap($layers, $data, $dictionary);
  expect($snapshot->exportDeclaration())->toBe($data['occupancy'])
    ->and($snapshot->collisionGrid)->toBe([[10, 0], [6, 7]])
    ->and($piece->occupancy)->toBe($mask)
    ->and($data)->toBe($original)
    ->and(MapCollisionResolver::resolveMap($layers, [], $dictionary)->collisionGrid)->toBe([[1, 0], [1, 1]])
    ->and(fn() => new MapPhysicalOccupancy($piece->occupancy, $layers))
    ->toThrow(InvalidArgumentException::class, 'cell 0, 0 must be a CollisionType case');
});
