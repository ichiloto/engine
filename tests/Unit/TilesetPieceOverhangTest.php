<?php

declare(strict_types=1);

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

function readOverhangPiece(array $extra = []): TilesetPiece
{
    return TilesetPiece::fromArray('shelf', $extra + ['name' => 'Shelf', 'layer' => 'fixtures',
        'overhang' => 1, 'glyphs' => ['  ', '##'], 'tiles' => ['furniture' => ['11 12', '21 22']],
        'occupancy' => [[null, null], [CollisionType::SOLID, CollisionType::SOLID]]], 'Synthetic tileset');
}

it('reads upper visual rows and authored ground rows while preserving the original declaration', function () {
    $source = ['overhang' => 2, 'glyphs' => ['<info>  </info>', '  ', '##', '##'],
        'tiles' => ['furniture' => ['11 12', '21 22', '31 32', '41 42']],
        'occupancy' => [[null, null], [null, null], [CollisionType::SOLID, CollisionType::NONE], [null, CollisionType::COUNTER]]];
    $original = $source;
    $piece = readOverhangPiece($source);
    $direct = new TilesetPiece('shelf', 'Shelf', 'fixtures', $piece->grid, $piece->tiles,
        occupancy: $piece->occupancy, overhang: 2);
    expect($piece->overhang)->toBe(2)->and($piece->groundHeight)->toBe(2)
        ->and([$piece->width, $piece->height])->toBe([2, 4])
        ->and($piece->glyphs[0])->toBe([' ', ' '])->and($piece->occupancy)->toBe($source['occupancy'])
        ->and($source)->toBe($original)->and($direct->groundHeight)->toBe(2)
        ->and($direct->occupancy)->toBe($piece->occupancy)
        ->and(TilesetPiece::FIELDS)->toContain('overhang');
});

it('keeps old pieces and positional constructor arguments unchanged with zero overhang', function () {
    $piece = readOverhangPiece(['overhang' => 0, 'glyphs' => ['xx', '##']]);
    $direct = new TilesetPiece('old', 'Old', 'fixtures', [['x'], ['x']], ['furniture' => [['11'], ['21']]],
        null, [], [], null, ['x', 'x'], null, [], [[CollisionType::SOLID], [CollisionType::SOLID]], []);
    $absent = TilesetPiece::fromArray('old', ['name' => 'Old', 'layer' => 'fixtures', 'glyphs' => ['x', 'x']], 'Synthetic tileset');
    expect([$piece->overhang, $piece->groundHeight])->toBe([0, 2])
        ->and([$direct->overhang, $direct->groundHeight])->toBe([0, 2])
        ->and([$absent->overhang, $absent->groundHeight])->toBe([0, 2])->and($absent->occupancy)->toBeNull();
});

it('refuses unsupported overhang counts with the piece identity', function (mixed $value) {
    expect(fn() => readOverhangPiece(['overhang' => $value]))->toThrow(InvalidArgumentException::class, 'shelf overhang');
})->with(['null' => [null], 'text' => ['1'], 'float' => [1.0], 'bool' => [true], 'negative' => [-1], 'full height' => [2], 'too tall' => [3]]);

it('requires top rows to leave both glyphs and physical cells unchanged', function (array $extra, string $reason) {
    expect(fn() => readOverhangPiece($extra))->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'glyph' => [['glyphs' => [' #', '##']], 'overhang cell 1, 0 must be a space'],
    'explicit walkable' => [['occupancy' => [[CollisionType::NONE, null], [CollisionType::SOLID, CollisionType::SOLID]]], 'overhang occupancy cell 0, 0 must be null'],
    'solid' => [['occupancy' => [[null, CollisionType::SOLID], [CollisionType::SOLID, CollisionType::SOLID]]], 'overhang occupancy cell 1, 0 must be null'],
    'door' => [['doorway' => [['x' => 0, 'y' => 0, 'entrance' => 'front']]], 'lies in its overhang'],
]);

it('requires an explicit footprint and refuses connected-piece overhangs', function () {
    expect(fn() => TilesetPiece::fromArray('shelf', ['name' => 'Shelf', 'layer' => 'fixtures', 'overhang' => 1, 'glyphs' => [' ', '#']], 'Synthetic tileset'))
        ->toThrow(InvalidArgumentException::class, 'overhang requires explicit occupancy');
    expect(fn() => TilesetPiece::fromArray('connected', ['name' => 'Wall', 'layer' => 'fixtures', 'overhang' => 1,
        'connects' => 'lines', 'glyphs' => ['horizontal' => '-', 'vertical' => '|', 'corner' => '+']], 'Synthetic tileset'))
        ->toThrow(InvalidArgumentException::class, 'connected pieces cannot declare an overhang');
    expect(fn() => new TilesetPiece('direct', 'Direct', 'fixtures', [[' '], ['#']], overhang: 1))
        ->toThrow(InvalidArgumentException::class, 'overhang requires explicit occupancy');
    expect(fn() => new TilesetPiece('direct', 'Direct', 'fixtures', [['#'], ['#']], occupancy: [[null], [CollisionType::SOLID]], overhang: 1))
        ->toThrow(InvalidArgumentException::class, 'overhang cell 0, 0 must be a space');
    expect(fn() => new TilesetPiece('direct', 'Direct', 'fixtures', [[' '], ['#']], connects: 'lines',
        occupancy: [[null], [CollisionType::SOLID]], overhang: 1))
        ->toThrow(InvalidArgumentException::class, 'connected pieces cannot declare an overhang');
});
