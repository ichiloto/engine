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

it('meets each declared entrance relation exactly and reports it one cell off in every direction', function (string $relation) {
    // The door is painted at the anchor, 2,4; 'on' is entered there and 'front' from 2,5.
    $entranceAt = $relation === 'front' ? [2, 5] : [2, 4];
    $doorway = [['x' => 0, 'y' => 0, ...($relation === 'default' ? [] : ['entrance' => $relation])]];
    [$data, $grid] = makeDoorwayMap([$entranceAt]);
    expect(inspectDoorways($data, $grid, $doorway))->toBe([]);
    foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
        [$data, $grid] = makeDoorwayMap([[$entranceAt[0] + $dx, $entranceAt[1] + $dy]]);
        $issues = inspectDoorways($data, $grid, $doorway);
        // An entrance moved behind the ground row also reports that the art hides it; the doorway is always reported.
        expect(array_values(array_filter($issues, static fn($issue): bool => $issue->path === ['worldObjects', 0])))->toHaveCount(1)
            ->and($issues[array_key_last($issues)]->cells)->toBe([[2, 4]]);
    }
})->with(['default', 'on', 'front']);

it('reports a door entered from the front whose entrance also lies on the painted door', function () {
    [$data, $grid] = makeDoorwayMap([[2, 4], [2, 5]]);
    $issues = inspectDoorways($data, $grid, [['x' => 0, 'y' => 0, 'entrance' => 'front']]);
    expect($issues)->toHaveCount(1)->and($issues[0]->cells)->toBe([[2, 4]])->and($issues[0]->message)->toContain('on the door itself');
    // The same entrance meets a door declared as walked onto.
    expect(inspectDoorways($data, $grid, [['x' => 0, 'y' => 0, 'entrance' => 'on']]))->toBe([]);
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
