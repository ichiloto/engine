<?php

use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

it('keeps independent underlying layers without changing stamped glyphs, tiles or source', function () {
  $data = ['name' => 'Mounted fixture', 'layer' => 'buildings', 'glyphs' => ['<info>X</info> '],
    'tiles' => ['objects' => ['12 0']], 'keeps' => ['walls', 'Floor-face_2']];
  $original = $data;
  $piece = TilesetPiece::fromArray('synthetic', $data, 'Synthetic tileset');
  expect($piece->keeps)->toBe(['walls', 'Floor-face_2'])
    ->and($piece->glyphs)->toBe([['X', ' ']])
    ->and($piece->tiles)->toBe(['objects' => [['12', '0']]])
    ->and($piece->getSourceGrid())->toBe([['<info>X</info>', ' ']])
    ->and([$piece->width, $piece->height])->toBe([2, 1])
    ->and($data)->toBe($original);
  $keeps = ['walls'];
  $direct = new TilesetPiece('synthetic', 'Mounted fixture', 'buildings', [['X']], keeps: $keeps);
  $keeps[0] = 'changed';
  expect($direct->keeps)->toBe(['walls'])
    ->and(new TilesetPiece('plain', 'Plain', 'fixtures', [['x']])->keeps)->toBe([]);
});

it('rejects malformed or overlapping kept layer declarations at the shared parser', function (mixed $keeps) {
  expect(fn() => TilesetPiece::fromArray('synthetic', [
    'name' => 'Mounted fixture', 'layer' => 'buildings', 'glyphs' => ['X'],
    'tiles' => ['objects' => ['12']], 'keeps' => $keeps,
  ], 'Synthetic tileset'))->toThrow(InvalidArgumentException::class);
})->with([
  'not a list' => ['walls'],
  'explicit null' => [null],
  'keyed list' => [['layer' => 'walls']],
  'duplicate' => [['walls', 'walls']],
  'written layer' => [['objects']],
  'empty name' => [['']],
  'numeric name' => [['2walls']],
  'path' => [['../walls']],
  'integer' => [[2]],
]);

it('retains independent underlays for connected shapes without changing their source or geometry', function () {
  $data = ['name' => 'Synthetic fence', 'layer' => 'fixtures', 'connects' => 'lines',
    'glyphs' => ['horizontal' => '<info>-</info>', 'vertical' => '|', 'corner' => '+'],
    'tiles' => ['objects' => ['horizontal' => '12', 'vertical' => '13', 'corner' => '14']]];
  $plain = TilesetPiece::fromArray('fence', $data, 'Synthetic');
  $keptData = $data + ['keeps' => ['walls', 'Floor-face_2']];
  $original = $keptData;
  $kept = TilesetPiece::fromArray('fence', $keptData, 'Synthetic');
  expect($plain->keeps)->toBe([])->and($kept->keeps)->toBe(['walls', 'Floor-face_2'])
    ->and($kept->shapes)->toBe($plain->shapes)
    ->and($kept->shapeTiles)->toBe($plain->shapeTiles)
    ->and($kept->getSourceShapeGrid())->toBe($data['glyphs'])
    ->and($kept->getSourceGrid())->toBe($plain->getSourceGrid())
    ->and($kept->glyphs)->toBe($plain->glyphs)
    ->and($kept->grid)->toBe($plain->grid)
    ->and($kept->tiles)->toBe([])
    ->and([$kept->width, $kept->height])->toBe([1, 1])
    ->and($kept->occupancy)->toBe($plain->occupancy)
    ->and($keptData)->toBe($original);
  foreach ($kept->shapes as $glyph) {
    expect($kept->isMember($glyph))->toBeTrue();
  }
  $keeps = ['walls'];
  $direct = new TilesetPiece('fence', 'Fence', 'fixtures', [['+']], connects: 'lines',
    shapes: $plain->shapes, shapeTiles: $plain->shapeTiles, keeps: $keeps);
  $keeps[0] = 'changed';
  expect($direct->keeps)->toBe(['walls']);
});

it('rejects malformed or written kept layers on connected shapes through both construction paths', function (mixed $keeps) {
  $data = ['name' => 'Synthetic fence', 'layer' => 'fixtures', 'connects' => 'lines',
    'glyphs' => ['horizontal' => '-', 'vertical' => '|', 'corner' => '+'], 'tiles' => ['objects' => '12']];
  expect(fn() => TilesetPiece::fromArray('fence', $data + ['keeps' => $keeps], 'Synthetic'))
    ->toThrow(InvalidArgumentException::class);
  if (is_array($keeps)) {
    expect(fn() => new TilesetPiece('fence', 'Fence', 'fixtures', [['+']], connects: 'lines',
      shapeTiles: ['objects' => ['horizontal' => '12', 'vertical' => '13', 'corner' => '14']], keeps: $keeps))
      ->toThrow(InvalidArgumentException::class);
  }
})->with([
  'not a list' => ['walls'],
  'explicit null' => [null],
  'keyed list' => [['layer' => 'walls']],
  'duplicate' => [['walls', 'walls']],
  'written shape layer' => [['objects']],
  'empty name' => [['']],
  'numeric name' => [['2walls']],
  'path' => [['../walls']],
  'integer' => [[2]],
]);

it('rejects keeping a plain or shaped written layer on direct connected construction', function (string $layer) {
  expect(fn() => new TilesetPiece('fence', 'Fence', 'fixtures', [['+']],
    tiles: ['plain' => [['12']]], connects: 'lines', shapeTiles: ['shaped' => ['horizontal' => '13', 'vertical' => '14', 'corner' => '15']],
    keeps: [$layer]))->toThrow(InvalidArgumentException::class, 'layers this piece does not write');
})->with(['plain', 'shaped']);
