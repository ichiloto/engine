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

it('rejects kept layers for connected pieces rather than inventing retention semantics', function () {
  $data = ['name' => 'Synthetic fence', 'layer' => 'fixtures', 'connects' => 'lines',
    'glyphs' => ['horizontal' => '-', 'vertical' => '|', 'corner' => '+'], 'tiles' => ['objects' => '12']];
  expect(TilesetPiece::fromArray('fence', $data, 'Synthetic')->keeps)->toBe([])
    ->and(fn() => TilesetPiece::fromArray('fence', $data + ['keeps' => ['walls']], 'Synthetic'))
    ->toThrow(InvalidArgumentException::class, 'connected pieces do not preserve')
    ->and(fn() => new TilesetPiece('fence', 'Fence', 'fixtures', [['+']], connects: 'lines', keeps: ['walls']))
    ->toThrow(InvalidArgumentException::class, 'connected pieces do not preserve');
});
