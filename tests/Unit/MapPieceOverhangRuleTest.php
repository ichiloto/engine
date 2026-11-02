<?php

declare(strict_types=1);

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Field\Validation\MapCompletenessValidator;
use Ichiloto\Engine\Field\Validation\MapPieceOverhangRule;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use Ichiloto\Engine\Util\Debug;

/** Synthetic geometry, independently authored physical cells and complete graphical stamps. */
function makeOverhangRuleMap(array $above = [], array $tileRows = ['objects' => ['11 12', '21 22']], array $stamps = [[1, 1]]): array
{
    $piece = TilesetPiece::fromArray('shelf', ['name' => 'Shelf', 'layer' => 'fixtures', 'overhang' => 1,
        'glyphs' => ['  ', '##'], 'occupancy' => [[null, null], [CollisionType::SOLID, CollisionType::SOLID]], 'tiles' => $tileRows], 'Synthetic tileset');
    $tileset = new Tileset('test', 'Test', [], above: $above, pieces: ['shelf' => $piece]);
    $tileLayers = [];
    foreach ($piece->tiles as $name => $rows) {
        $grid = array_fill(0, 5, array_fill(0, 7, '0'));
        foreach ($stamps as [$left, $top]) {
            foreach ($rows as $dy => $row) { foreach ($row as $dx => $tile) { $grid[$top + $dy][$left + $dx] = $tile; } }
        }
        $tileLayers[] = new MapTileLayer($name, count($tileLayers), 'synthetic', implode("\n", array_map(fn($row) => implode(' ', $row), $grid)));
    }
    $layers = new MapLayerSet([new MapLayer('fixtures', 0, false, 'synthetic', implode("\n", array_fill(0, 5, '       ')))]);
    $physical = array_fill(0, 5, array_fill(0, 7, CollisionType::NONE));
    return [new MapGraphics($tileset, $tileLayers), $layers, $physical];
}

it('uses physical occupancy rather than terminal glyphs to judge wall backing', function (CollisionType $type) {
    [$graphics, $layers, $physical] = makeOverhangRuleMap();
    $physical[1][1] = $physical[1][2] = $type;
    $snapshot = $physical;
    $occupancy = new MapPhysicalOccupancy($physical, $layers);
    $issues = MapPieceOverhangRule::inspect('test/map', $graphics, $occupancy);
    expect($physical)->toBe($snapshot)->and($occupancy->exportDeclaration())->toBe($snapshot);
    if ($type->blocksMovement()) { expect($issues)->toBe([]); return; }
    expect($issues)->toHaveCount(1)->and($issues[0]->rule)->toBe('piece.overhang')
        ->and($issues[0]->cells)->toBe([[1, 1], [2, 1]])->and($issues[0]->message)->toContain('Shelf at (1, 2)');
})->with(fn() => array_map(fn($type) => [$type], array_values(array_filter(CollisionType::cases(), fn($type) => $type !== CollisionType::PASS_THROUGH))));

it('allows only above-character tiles over walkable backing and ignores empty visual cells', function (array $above, array $tiles, array $badCells) {
    [$graphics, $layers, $physical] = makeOverhangRuleMap($above, $tiles);
    $issues = MapPieceOverhangRule::inspect('test/map', $graphics, new MapPhysicalOccupancy($physical, $layers));
    expect($issues === [])->toBe($badCells === []);
    if ($badCells !== []) { expect($issues[0]->cells)->toBe($badCells); }
})->with([
    'all top tiles above' => [[11, 12], ['objects' => ['11 12', '21 22']], []],
    'only one top above' => [[11], ['objects' => ['11 12', '21 22']], [[2, 1]]],
    'empty top row' => [[], ['objects' => ['0 0', '21 22']], []],
    'empty top cell' => [[], ['objects' => ['0 12', '21 22']], [[2, 1]]],
    'mixed layers' => [[11, 12], ['objects' => ['11 12', '21 22'], 'trim' => ['31 0', '0 0']], [[1, 1]]],
    'all layers above' => [[11, 12, 31], ['objects' => ['11 12', '21 22'], 'trim' => ['31 0', '0 0']], []],
]);

it('finds every complete stamp while leaving partial copies and unused catalogue pieces alone', function () {
    [$graphics, $layers, $physical] = makeOverhangRuleMap(stamps: [[1, 1], [4, 2]]);
    $issues = MapPieceOverhangRule::inspect('test/map', $graphics, new MapPhysicalOccupancy($physical, $layers));
    expect(array_column($issues, 'cells'))->toBe([[[1, 1], [2, 1]], [[4, 2], [5, 2]]]);
    $partial = new MapGraphics($graphics->tileset, [new MapTileLayer('objects', 0, 'synthetic', "0 11 12\n0 21 99")]);
    expect(MapPieceOverhangRule::inspect('test/map', $partial))->toBe([]);
    [$unused] = makeOverhangRuleMap(stamps: []);
    expect(MapPieceOverhangRule::inspect('test/map', $unused))->toBe([]);
    [$above] = makeOverhangRuleMap(above: [11, 12]);
    expect(MapPieceOverhangRule::inspect('test/map', $above))->toBe([]);
});

it('reports missing physical input only for placed overhangs that require backing', function () {
    [$graphics] = makeOverhangRuleMap();
    $issues = MapPieceOverhangRule::inspect('test/map', $graphics);
    expect($issues)->toHaveCount(1)->and($issues[0]->path)->toBe(['occupancy'])
        ->and($issues[0]->message)->toContain('resolved physical occupancy');
});

it('runs the shared check with declared or caller-resolved legacy occupancy and leaves old maps unchanged', function () {
    [$graphics, $layers, $physical] = makeOverhangRuleMap();
    $physical[1][1] = $physical[1][2] = CollisionType::SOLID;
    $root = createTestDirectory('ichiloto-overhang-');
    $oldDebug = new ReflectionClass(Debug::class)->getStaticProperties();
    Debug::configure(['log_directory' => $root . '/logs']);
    try {
        $validate = static fn(array $data, ?MapPhysicalOccupancy $occupancy = null, ?MapGraphics $shown = null): array => array_values(array_filter(
            MapCompletenessValidator::validate('test/map', $data, array_fill(0, 5, array_fill(0, 7, ' ')), $layers,
                $shown ?? $graphics, $root, fn() => null, occupancy: $occupancy), fn($issue) => $issue->rule === MapPieceOverhangRule::RULE));
        expect($validate(['occupancy' => $physical]))->toBe([])
            ->and($validate([], new MapPhysicalOccupancy($physical, $layers)))->toBe([])
            ->and($validate([]))->toHaveCount(1);
        $physical[1][1] = CollisionType::NONE;
        expect($validate(['occupancy' => $physical])[0]->cells)->toBe([[1, 1]]);
        $oldPiece = new TilesetPiece('old', 'Old', 'fixtures', [['#'], ['#']], ['objects' => [['11'], ['21']]]);
        $oldGraphics = new MapGraphics(new Tileset('old', 'Old', [], pieces: ['old' => $oldPiece]), $graphics->layers);
        expect($validate([], shown: $oldGraphics))->toBe([]);
        [$unused] = makeOverhangRuleMap(stamps: []);
        expect($validate([], shown: $unused))->toBe([]);
    } finally {
        foreach ($oldDebug as $name => $value) { new ReflectionProperty(Debug::class, $name)->setValue(null, $value); }
    }
});


it('checks every visual row when a piece rises more than one row above its base', function () {
    $piece = TilesetPiece::fromArray('tall', ['name' => 'Tall shelf', 'layer' => 'fixtures', 'overhang' => 2,
        'glyphs' => [' ', ' ', '#'], 'tiles' => ['objects' => ['11', '12', '21']],
        'occupancy' => [[null], [null], [CollisionType::SOLID]]], 'Synthetic tileset');
    $graphics = new MapGraphics(new Tileset('test', 'Test', [], pieces: ['tall' => $piece]),
        [new MapTileLayer('objects', 0, 'synthetic', "11\n12\n21")]);
    $layers = new MapLayerSet([new MapLayer('fixtures', 0, false, 'synthetic', " \n \n#")]);
    $physical = new MapPhysicalOccupancy([[CollisionType::SOLID], [CollisionType::NONE], [CollisionType::SOLID]], $layers);
    $issues = MapPieceOverhangRule::inspect('test/map', $graphics, $physical);
    expect($issues)->toHaveCount(1)->and($issues[0]->cells)->toBe([[0, 1]])
        ->and($issues[0]->message)->toContain('Tall shelf at (0, 2)');
});
