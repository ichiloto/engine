<?php

use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Events\Triggers\DialogueEventTrigger;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\Validation\MapCompletenessValidator;
use Ichiloto\Engine\Field\Validation\MapDoorwayRule;

/** A synthetic 6x6 field with a 2x3 cave whose ground row is 4 and whose painted opening is its anchor cell and the next. */
function makeDoorwayMap(array $entranceCells, array $object = []): array
{
    $grid = array_fill(0, 6, array_fill(0, 6, ' '));
    foreach ($entranceCells as [$x, $y]) { $grid[$y][$x] = 'M'; }
    return [[
        'worldObjects' => [['id' => 'cave-1', 'resource' => 'cave', 'anchor' => ['x' => 2, 'y' => 4],
            'covers' => ['layer' => 'ground', 'cells' => [[2, 2], [3, 2], [2, 3], [3, 3], [2, 4], [3, 4]], 'tileLayers' => []], ...$object]],
        'events' => ['M' => ['class' => TransferPlayerTrigger::class, 'data' => ['destinationMap' => 'cave/inside', 'spawnPoint' => ['x' => 1, 'y' => 1]]]],
    ], $grid];
}

function inspectDoorways(array $data, array $grid, array $doorway = [['x' => 0, 'y' => 0], ['x' => 1, 'y' => 0]]): array
{
    $catalog = FieldPresentationCatalog::fromArray(['resources' => ['cave' => ['name' => 'Cave', 'pivot' => ['x' => .5, 'y' => 1],
        'sprites2d' => ['asset' => 'Graphics/cave.png'], ...($doorway === [] ? [] : ['doorway' => $doorway])]]], '/missing-synthetic-root');
    $layers = new MapLayerSet([new MapLayer('ground', 0, false, 'synthetic', implode("\n", array_fill(0, 6, '      ')))]);
    return array_values(array_filter(MapCompletenessValidator::validateDoorways('test/field', $data, $grid, $layers, '/missing-synthetic-root', $catalog),
        static fn($issue): bool => $issue->rule === MapDoorwayRule::RULE));
}

it('accepts an entrance under the painted doorway on the ground row or in front of it', function (array $cells, array $doorway) {
    [$data, $grid] = makeDoorwayMap($cells);
    expect(inspectDoorways($data, $grid, $doorway))->toBe([]);
})->with([
    'ground row' => [[[2, 4], [3, 4]], [['x' => 0, 'y' => 0], ['x' => 1, 'y' => 0]]],
    'in front' => [[[2, 5], [3, 5]], [['x' => 0, 'y' => 1], ['x' => 1, 'y' => 1]]],
]);

it('reports an entrance behind the ground row inside the object, whatever its art or layer', function () {
    [$data, $grid] = makeDoorwayMap([[2, 3], [3, 3], [2, 4], [3, 4]]);
    $issues = inspectDoorways($data, $grid);
    expect($issues)->toHaveCount(1)->and($issues[0]->marker)->toBe('M')->and($issues[0]->cells)->toBe([[2, 3], [3, 3]])
        ->and($issues[0]->message)->toContain('cave-1')->toContain('ground row is 4');
});

it('reports a painted doorway with no entrance under it', function () {
    [$data, $grid] = makeDoorwayMap([[2, 4]]);
    $issues = inspectDoorways($data, $grid);
    expect($issues)->toHaveCount(1)->and($issues[0]->cells)->toBe([[3, 4]])->and($issues[0]->path)->toBe(['worldObjects', 0]);
    // A doorway one cell beside its entrance is the same defect.
    [$data, $grid] = makeDoorwayMap([[4, 4], [5, 4]]);
    expect(inspectDoorways($data, $grid)[0]->cells)->toBe([[2, 4], [3, 4]]);
});

it('ignores overhang over open floor and entrances outside the covered cells', function () {
    [$data, $grid] = makeDoorwayMap([[4, 2], [4, 3]]);
    expect(inspectDoorways($data, $grid, []))->toBe([]);
    // Non-entrance events, such as an inspection under a canopy, are the art's ordinary overhang.
    $data['events']['M']['class'] = DialogueEventTrigger::class;
    $data['events']['M']['data'] = ['dialogue' => []];
    [, $hidden] = makeDoorwayMap([[2, 3]]);
    expect(inspectDoorways($data, $hidden, []))->toBe([]);
});

it('lets an object and an entrance that never show together pass each other', function () {
    [$data, $grid] = makeDoorwayMap([[2, 3]], ['conditions' => [['type' => 'event', 'name' => 'cave_collapsed']]]);
    $data['events']['M']['conditions'] = [['type' => 'event', 'name' => 'cave_collapsed', 'negate' => true]];
    expect(inspectDoorways($data, $grid, []))->toBe([]);
    unset($data['events']['M']['conditions']);
    expect(inspectDoorways($data, $grid, []))->toHaveCount(1);
});
