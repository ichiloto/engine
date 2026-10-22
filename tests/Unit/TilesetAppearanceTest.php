<?php

use Ichiloto\Engine\Rendering\Tilesets\TileAppearance;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;

/** What tiles show at ground level, as validation reads it from a synthetic tileset. */

function createAppearanceTileset(array $appearance = [], array $extra = []): Tileset
{
    return Tileset::fromArray('synthetic', ['name' => 'Synthetic', 'sheets' => ['A2' => 'Graphics/A2.png'],
        ...$extra, ...($appearance === [] ? [] : ['appearance' => $appearance])]);
}

it('reads RPG Maker sheet kinds by default and leaves A5 and the upper sheets unknown', function () {
    $tileset = createAppearanceTileset();
    $a4Top = TileId::getAutotileId(80, 0);
    $a4Face = TileId::getAutotileId(88, 0);

    expect($tileset->classifyAppearance(TileId::A1 + 5))->toBe(TileAppearance::WATER)
        ->and($tileset->classifyAppearance(TileId::A2 + 47))->toBe(TileAppearance::GROUND)
        ->and($tileset->classifyAppearance(TileId::A3))->toBe(TileAppearance::BARRIER)
        ->and($tileset->classifyAppearance($a4Top))->toBe(TileAppearance::GROUND)
        ->and($tileset->classifyAppearance($a4Face))->toBe(TileAppearance::BARRIER)
        ->and($tileset->classifyAppearance(TileId::A5 + 3))->toBeNull()
        ->and($tileset->classifyAppearance(7))->toBeNull()
        ->and($tileset->classifyAppearance(TileId::EMPTY))->toBeNull();
});

it('takes a tile\'s own class over its marks, its marks over its sheet, and its sheet over the default', function () {
    $tileset = createAppearanceTileset(['barrier' => ['B', 'A2'], 'ground' => [7, TileId::A2 + 48 + 3]],
        ['above' => [9], 'tables' => [TileId::A2 + 96]]);

    expect($tileset->classifyAppearance(7))->toBe(TileAppearance::GROUND)
        ->and($tileset->classifyAppearance(8))->toBe(TileAppearance::BARRIER)
        ->and($tileset->classifyAppearance(9))->toBe(TileAppearance::OVERHEAD)
        // Any shape of an autotile names its kind.
        ->and($tileset->classifyAppearance(TileId::A2 + 48 + 20))->toBe(TileAppearance::GROUND)
        ->and($tileset->classifyAppearance(TileId::A2))->toBe(TileAppearance::BARRIER)
        ->and(createAppearanceTileset(extra: ['tables' => [TileId::A2 + 96]])->classifyAppearance(TileId::A2 + 96 + 4))
            ->toBe(TileAppearance::BARRIER);
});

it('refuses an appearance that names a tile or sheet twice, an unknown class or a sheet that is not RPG Maker\'s', function (array $appearance, string $message) {
    expect(fn() => createAppearanceTileset($appearance))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'tile twice' => [['ground' => [7], 'barrier' => [7]], 'tile 7 as both'],
    'kind twice' => [['ground' => [TileId::A2], 'water' => [TileId::A2 + 2]], 'as both'],
    'sheet twice' => [['ground' => ['B'], 'water' => ['B']], 'sheet B as both'],
    'unknown class' => [['lava' => [7]], "'lava' is not a class"],
    'unknown sheet' => [['ground' => ['F']], "'F' is not an RPG Maker sheet"],
    'not a tile' => [['ground' => [99999]], 'not an RPG Maker tile identity'],
]);
