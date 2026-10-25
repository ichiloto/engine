<?php

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\Enumerations\DoorwayEntrance;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Field\Validation\MapCompletenessValidator;
use Ichiloto\Engine\Field\Validation\MapDoorwayRule;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/** A synthetic 3x2 building: a solid roof row over a wall row with an opening in its middle. */
function getDoorwayPieceSource(array $doorway = [['x' => 1, 'y' => 1]], array $extra = []): array
{
    return ['name' => 'Hut', 'layer' => 'buildings', 'glyphs' => ['/^\\', '|∩|'],
        'tiles' => ['houses' => ['801 802 803', '809 810 811']],
        'occupancy' => [[CollisionType::SOLID, CollisionType::SOLID, CollisionType::SOLID],
            [CollisionType::SOLID, CollisionType::NONE, CollisionType::SOLID]],
        ...($doorway === [] ? [] : ['doorway' => $doorway]), ...$extra];
}

/** A synthetic 8x6 field whose `houses` tiles hold the given stamps of the hut, and whose transfers stand on the given cells. */
function inspectPieceDoorways(array $stamps, array $entranceCells, array $doorway = [['x' => 1, 'y' => 1]]): array
{
    $tileset = Tileset::fromArray('synthetic', ['name' => 'Synthetic', 'sheets' => ['E' => 'Graphics/synthetic.png'],
        'pieces' => ['hut' => getDoorwayPieceSource($doorway)]]);
    $tiles = array_fill(0, 6, array_fill(0, 8, '0'));
    foreach ($stamps as [$x, $y]) {
        foreach ([['801', '802', '803'], ['809', '810', '811']] as $dy => $row) {
            foreach ($row as $dx => $tile) { $tiles[$y + $dy][$x + $dx] = $tile; }
        }
    }
    $graphics = new MapGraphics($tileset, [new MapTileLayer('houses', 0, 'houses', implode("\n", array_map(
        static fn(array $row): string => implode(' ', $row), $tiles)))]);
    $grid = array_fill(0, 6, array_fill(0, 8, ' '));
    foreach ($entranceCells as [$x, $y]) { $grid[$y][$x] = 'M'; }
    $data = ['events' => ['M' => ['class' => TransferPlayerTrigger::class,
        'data' => ['destinationMap' => 'hut/inside', 'spawnPoint' => ['x' => 1, 'y' => 1]]]]];
    $layers = new MapLayerSet([new MapLayer('buildings', 0, false, 'synthetic', implode("\n", array_fill(0, 6, str_repeat(' ', 8))))]);
    return array_values(array_filter(MapCompletenessValidator::validateDoorways('test/field', $data, $grid, $layers,
        '/missing-synthetic-root', null, $graphics), static fn($issue): bool => $issue->rule === MapDoorwayRule::RULE));
}

it('reads a piece doorway as cells relative to its top-left cell, entered on the opening unless declared otherwise', function () {
    $piece = TilesetPiece::fromArray('hut', getDoorwayPieceSource([['x' => 1, 'y' => 1], ['x' => 0, 'y' => 1, 'entrance' => 'front']]),
        'Tileset synthetic');
    $plain = TilesetPiece::fromArray('hut', getDoorwayPieceSource([]), 'Tileset synthetic');

    expect($piece->doorway)->toBe([
        ['x' => 1, 'y' => 1, 'entrance' => DoorwayEntrance::ON],
        ['x' => 0, 'y' => 1, 'entrance' => DoorwayEntrance::FRONT],
    ])->and($plain->doorway)->toBe([])
        ->and(TilesetPiece::FIELDS)->toContain('doorway');
});

it('refuses a piece doorway that cannot be met as drawn', function (array $source, string $message) {
    expect(static fn() => TilesetPiece::fromArray('hut', $source, 'Tileset synthetic'))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'outside the piece' => [getDoorwayPieceSource([['x' => 3, 'y' => 1]]), 'doorway cell 3, 1 lies outside its 3 by 2 cells'],
    'above the piece' => [getDoorwayPieceSource([['x' => 1, 'y' => -1]]), 'doorway cell 1, -1 lies outside'],
    'walked onto a solid cell' => [getDoorwayPieceSource([['x' => 0, 'y' => 1]]), 'doorway cell 0, 1 is entered on its opening'],
    'without tiles to find it by' => [getDoorwayPieceSource(extra: ['tiles' => ['houses' => ['0 0 0', '0 0 0']]]),
        'doorway needs graphical tiles to identify its stamped instances'],
    'repeated cell' => [getDoorwayPieceSource([['x' => 1, 'y' => 1], ['x' => 1, 'y' => 1]]), 'doorway requires distinct integer x/y offsets'],
    'unknown entrance' => [getDoorwayPieceSource([['x' => 1, 'y' => 1, 'entrance' => 'behind']]), 'doorway entrance must be one of: on, front'],
    'connected piece' => [['name' => 'Wall', 'layer' => 'terrain', 'connects' => 'lines',
        'glyphs' => ['horizontal' => '=', 'vertical' => '|', 'corner' => '+'], 'tiles' => ['border' => '5888'], 'doorway' => [['x' => 0, 'y' => 0]]],
        'connected pieces do not paint doors'],
]);

it('accepts a front door painted on a solid face of the piece', function () {
    $source = getDoorwayPieceSource([['x' => 0, 'y' => 1, 'entrance' => 'front']]);
    expect(TilesetPiece::fromArray('hut', $source, 'Tileset synthetic')->doorway[0]['entrance'])->toBe(DoorwayEntrance::FRONT);
});

it('finds every whole copy of a stamped piece by its nonzero tiles and nothing partly painted over', function () {
    $tileset = Tileset::fromArray('synthetic', ['name' => 'Synthetic', 'sheets' => ['E' => 'Graphics/synthetic.png'],
        'pieces' => ['hut' => getDoorwayPieceSource()]]);
    $graphics = new MapGraphics($tileset, [new MapTileLayer('houses', 0, 'houses', implode("\n", [
        '801 802 803 0 801 802 803 0',
        '809 810 811 0 809 999 811 0',
        '0 0 801 802 803 0 0 0',
        '0 0 809 810 811 0 0 0',
    ]))]);

    expect($graphics->findPieceStamps($tileset->pieces['hut']))->toBe([[0, 0], [2, 2]]);
});

it('meets each stamped copy of a piece\'s doors with the map\'s entrances', function () {
    // Copies at 1,1 and 4,3 paint their doors at 2,2 and 5,4.
    expect(inspectPieceDoorways([[1, 1], [4, 3]], [[2, 2], [5, 4]]))->toBe([]);

    $issues = inspectPieceDoorways([[1, 1], [4, 3]], [[2, 2], [5, 5]]);
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->cells)->toBe([[5, 4]])
        ->and($issues[0]->message)->toBe("Piece 'hut' at 4,3 paints a door at 5,4, where no entrance is.");
});

it('meets a front door from the cell before it and never on the door itself', function () {
    $front = [['x' => 1, 'y' => 1, 'entrance' => 'front']];
    expect(inspectPieceDoorways([[1, 1]], [[2, 3]], $front))->toBe([])
        ->and(inspectPieceDoorways([[1, 1]], [[3, 3]], $front)[0]->message)->toContain('entered from the front, with no entrance at 2,3')
        ->and(inspectPieceDoorways([[1, 1]], [[2, 2], [2, 3]], $front)[0]->message)->toContain('an entrance lies on the door itself');
});

it('judges no piece doorway on a map without graphics or without a stamped copy', function () {
    expect(inspectPieceDoorways([], []))->toBe([]);
    $layers = new MapLayerSet([new MapLayer('buildings', 0, false, 'synthetic', '  ')]);
    expect(MapCompletenessValidator::validateDoorways('test/field', [], [[' ', ' ']], $layers, '/missing-synthetic-root'))->toBe([]);
});
