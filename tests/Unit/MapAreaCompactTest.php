<?php

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Field\EncounterTable;
use Ichiloto\Engine\Field\MapAreaCatalog;
use Ichiloto\Engine\Field\MapLandmarkCatalog;
use Ichiloto\Engine\Field\MapSourceReader;
use Ichiloto\Engine\Field\Validation\MapEventResolver;

it('round trips compact territories without filling holes or joining disconnected pieces', function (string $kind) {
    $grid = array_fill(0, 24, array_fill(0, 32, ' '));
    $cells = [];
    foreach ($grid as $y => $row) {
        foreach ($row as $x => $_) {
            $inside = match ($kind) {
                'polygon with lake' => $x >= intdiv($y, 4) && $x < 29 - intdiv($y, 3)
                    && !($x >= 10 && $x <= 16 && $y >= 6 && $y <= 14),
                'disconnected' => ($y < 5 && $x < 20) || ($y >= 18 && $x >= 23) || ($x === 12 && $y === 11),
                'rectangle' => $x >= 2 && $x <= 27 && $y >= 4 && $y <= 19,
                'sparse' => ($x + $y) % 2 === 0,
            };
            if ($inside) { $cells[] = [$x, $y]; }
        }
    }
    $area = CellArea::fromCells(array_reverse($cells));
    $encoded = MapAreaCatalog::encodeArea($area);
    $json = json_encode($encoded, JSON_THROW_ON_ERROR);
    $decoded = MapAreaCatalog::readMap(['areas' => [['id' => 'region',
        'area' => json_decode($json, true, flags: JSON_THROW_ON_ERROR)]]], $grid)->getArea('region');
    expect($decoded->cells)->toBe($area->cells)
        ->and(MapAreaCatalog::encodeArea($decoded))->toBe($encoded)
        ->and(strlen($json))->toBeLessThanOrEqual(strlen(json_encode($area->toArray(), JSON_THROW_ON_ERROR)))
        ->and($area->toArray())->toBe(['cells' => $area->cells]);
    foreach ($grid as $y => $row) {
        foreach ($row as $x => $_) { expect($decoded->containsCell($x, $y))->toBe(in_array([$x, $y], $cells, true)); }
    }
    expect(array_key_first($encoded))->toBe(match ($kind) {
        'rectangle' => 'x', 'sparse' => 'cells', default => 'runs',
    });
})->with(['polygon with lake', 'disconnected', 'rectangle', 'sparse']);

it('reads unordered and adjacent runs on ragged maps in cell reading order', function () {
    $grid = [array_fill(0, 7, ' '), array_fill(0, 2, ' '), [], array_fill(0, 5, ' ')];
    $area = MapAreaCatalog::readMap(['areas' => [['id' => 'region', 'area' => [
        'runs' => [[3, 3, 2], [0, 4, 3], [1, 0, 2], [0, 1, 3]],
    ]]]], $grid)->getArea('region');
    expect($area->cells)->toBe([[1, 0], [2, 0], [3, 0], [4, 0], [5, 0], [6, 0], [0, 1], [1, 1], [3, 3], [4, 3]])
        ->and(MapAreaCatalog::encodeArea($area))->toBe(['runs' => [[0, 1, 6], [1, 0, 2], [3, 3, 2]]]);
});

it('refuses malformed or unbounded runs through the same map validation boundary', function (array $shape) {
    $grid = [array_fill(0, 5, ' '), array_fill(0, 2, ' '), []];
    $map = ['areas' => [['id' => 'region', 'area' => $shape]]];
    expect(fn() => MapAreaCatalog::readMap($map, $grid))->toThrow(InvalidArgumentException::class);
    $checked = MapEventResolver::inspect($map, $grid, 'synthetic/compact');
    expect($checked->issues)->toHaveCount(1)->and($checked->issues[0]->rule)->toBe('map.area');
})->with([
    'empty' => [['runs' => []]],
    'not a list' => [['runs' => ['row' => [0, 0, 1]]]],
    'not a triple' => [['runs' => [[0, 0]]]],
    'named triple' => [['runs' => [['y' => 0, 'x' => 0, 'length' => 1]]]],
    'fractional' => [['runs' => [[0, 0.5, 1]]]],
    'numeric text' => [['runs' => [['0', 0, 1]]]],
    'boolean' => [['runs' => [[0, 0, true]]]],
    'negative row' => [['runs' => [[-1, 0, 1]]]],
    'negative start' => [['runs' => [[0, -1, 1]]]],
    'zero length' => [['runs' => [[0, 0, 0]]]],
    'negative length' => [['runs' => [[0, 0, -1]]]],
    'off map row' => [['runs' => [[3, 0, 1]]]],
    'missing ragged cell' => [['runs' => [[1, 1, 2]]]],
    'empty physical row' => [['runs' => [[2, 0, 1]]]],
    'huge start' => [['runs' => [[0, PHP_INT_MAX, 1]]]],
    'huge length' => [['runs' => [[0, 1, PHP_INT_MAX]]]],
    'overlapping' => [['runs' => [[0, 0, 3], [0, 2, 2]]]],
    'repeated' => [['runs' => [[0, 0, 1], [0, 0, 1]]]],
    'expanded cell budget' => [['runs' => [[0, 0, 5], [0, 0, 5]]]],
    'too many runs' => [['runs' => array_fill(0, 8, [0, 0, 1])]],
    'two owners' => [['runs' => [[0, 0, 1]], 'cells' => [[0, 0]]]],
    'rectangle mixed in' => [['runs' => [[0, 0, 1]], 'x' => 0]],
]);

it('shares compact named areas with events landmarks and encounter weights', function () {
    $grid = array_fill(0, 4, array_fill(0, 6, ' '));
    $map = ['areas' => [['id' => 'region', 'area' => ['runs' => [[0, 0, 4], [1, 0, 1], [1, 3, 1], [3, 5, 1]]]]],
        'events' => ['A' => ['class' => ScriptEventTrigger::class,
            'subject' => ['kind' => 'area', 'id' => 'region'],
            'data' => ['mode' => 'auto', 'script' => [['type' => 'record_event', 'name' => 'visited']]]]],
        'landmarks' => [['id' => 'name', 'kind' => 'landmark', 'anchor' => ['area' => 'region'],
            'name' => ['message' => 'test.name', 'default' => 'Region']]]];
    $before = $map;
    $resolved = MapSourceReader::resolveEvents($map, $grid);
    $resolvedMap = [...$map, 'events' => $resolved];
    $landmark = MapLandmarkCatalog::readMap($resolvedMap, $grid)->landmarks[0];
    $table = EncounterTable::readBlock(['troops' => ['Scoped' => ['weight' => 2, 'areas' => ['region']], 'Anywhere' => 1],
        'tiles' => 'any'], MapAreaCatalog::readMap($map, $grid));
    expect($map)->toBe($before)->and($resolved[0]['area']['cells'])->toBe($landmark->area->cells)
        ->and($resolved[0]['data'])->toBe($before['events']['A']['data']);
    foreach ($grid as $y => $row) {
        foreach ($row as $x => $_) {
            $inside = $landmark->area->containsCell($x, $y);
            expect($table->getTroopWeightsAt(new Vector2($x, $y)))->toBe($inside ? ['Scoped' => 2, 'Anywhere' => 1] : ['Anywhere' => 1]);
        }
    }
});
