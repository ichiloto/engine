<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Events\Triggers\EventTriggerFactory;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Field\MapAreaCatalog;
use Ichiloto\Engine\Field\MapLandmarkCatalog;
use Ichiloto\Engine\Field\MapKnowledge;
use Ichiloto\Engine\Field\MapSourceReader;
use Ichiloto\Engine\Field\Validation\MapEventResolver;

it('shares exact named territory across events without physical or graphical side effects', function () {
    $grid = array_fill(0, 4, array_fill(0, 5, ' '));
    $map = ['areas' => [['id' => 'survey-reach', 'area' => ['cells' => [[1, 1], [2, 1], [3, 2]]]]],
        'events' => []];
    foreach (['A', 'B'] as $marker) {
        $map['events'][$marker] = ['class' => ScriptEventTrigger::class,
            'subject' => ['kind' => 'area', 'id' => 'survey-reach'],
            'conditions' => [['type' => 'event', 'name' => 'dispatched']],
            'data' => ['mode' => 'auto', 'script' => [['type' => 'record_event', 'name' => 'arrived']]]];
    }
    $before = $map;
    $resolved = MapSourceReader::resolveEvents($map, $grid);
    expect($map)->toBe($before)->and($resolved[0]['area'])->toBe($resolved[1]['area']);
    $event = EventTriggerFactory::create($resolved[0], 'synthetic/map');
    expect($event->area->containsCell(2, 2))->toBeFalse()->and($event->area->containsCell(3, 2))->toBeTrue()
        ->and($event->runsAutomatically())->toBeTrue()->and($event->marker)->toBe('A');
    $state = new GameState();
    $event->bind($state);
    expect($event->isAvailable())->toBeFalse();
    $state->recordStoryEvent('dispatched');
    expect($event->isAvailable())->toBeTrue();
    $map['areas'][0]['area'] = ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 1];
    $moved = MapSourceReader::resolveEvents($map, $grid);
    expect($moved[0]['area']['cells'])->toBe([[0, 0], [1, 0]])
        ->and($moved[1]['area'])->toBe($moved[0]['area'])
        ->and($moved[0]['conditions'])->toBe($before['events']['A']['conditions']);
});

it('validates all declared areas even when no event references them', function (mixed $areas) {
    $grid = array_fill(0, 3, array_fill(0, 4, ' '));
    $result = MapEventResolver::inspect(['areas' => $areas], $grid, 'synthetic/map');
    expect($result->issues)->toHaveCount(1)->and($result->issues[0]->rule)->toBe('map.area')
        ->and($result->issues[0]->path)->toBe(['areas']);
    expect(fn() => $result->requireEvents())->toThrow(InvalidArgumentException::class);
})->with([
    'null' => [null],
    'object table' => [['district' => ['id' => 'district', 'area' => ['cells' => [[0, 0]]]]]],
    'duplicate id' => [[['id' => 'district', 'area' => ['cells' => [[0, 0]]]], ['id' => 'district', 'area' => ['cells' => [[1, 0]]]]]],
    'bad id' => [[['id' => '../district', 'area' => ['cells' => [[0, 0]]]]]],
    'missing geometry' => [[['id' => 'district']]],
    'presentation data' => [[['id' => 'district', 'area' => ['cells' => [[0, 0]]], 'image' => 'not-geometry.png']]],
    'duplicate cells' => [[['id' => 'district', 'area' => ['cells' => [[0, 0], [0, 0]]]]]],
    'outside cells' => [[['id' => 'district', 'area' => ['cells' => [[4, 0]]]]]],
    'negative cells' => [[['id' => 'district', 'area' => ['cells' => [[0, -1]]]]]],
    'fractional cells' => [[['id' => 'district', 'area' => ['cells' => [[1.5, 0]]]]]],
    'empty cells' => [[['id' => 'district', 'area' => ['cells' => []]]]],
    'multiple shapes' => [[['id' => 'district', 'area' => ['cells' => [[0, 0]], 'width' => 2]]]],
    'oversized rectangle' => [[['id' => 'district', 'area' => ['x' => 0, 'y' => 0, 'width' => PHP_INT_MAX, 'height' => PHP_INT_MAX]]]],
    'outside rectangle' => [[['id' => 'district', 'area' => ['x' => 3, 'y' => 1, 'width' => 2, 'height' => 1]]]],
    'negative rectangle' => [[['id' => 'district', 'area' => ['x' => -1, 'y' => 0, 'width' => 1, 'height' => 1]]]],
    'too many' => [array_fill(0, MapAreaCatalog::MAX_AREAS + 1, ['id' => 'district', 'area' => ['cells' => [[0, 0]]]])],
]);

it('rejects dangling area references and multiple geometry owners', function (Closure $change) {
    $grid = array_fill(0, 3, array_fill(0, 4, ' '));
    $map = ['areas' => [['id' => 'district', 'area' => ['cells' => [[1, 1]]]]],
        'events' => ['A' => ['class' => ScriptEventTrigger::class, 'subject' => ['kind' => 'area', 'id' => 'district'],
            'data' => ['mode' => 'auto', 'script' => []]]]];
    $change($map, $grid);
    $result = MapEventResolver::inspect($map, $grid, 'synthetic/map');
    expect($result->issues)->toHaveCount(1)->and($result->issues[0]->rule)->toBe('event.subject');
})->with([
    'missing area' => [function (&$map, &$grid) { $map['areas'] = []; }],
    'painted marker' => [function (&$map, &$grid) { $grid[0][0] = 'A'; }],
    'inline area' => [function (&$map, &$grid) { $map['events']['A']['area'] = ['cells' => [[0, 0]]]; }],
    'extra reference data' => [function (&$map, &$grid) { $map['events']['A']['subject']['cells'] = [[0, 0]]; }],
]);

it('checks ragged physical geometry without filling missing cells', function () {
    $grid = [[' ', ' '], [' ']];
    $map = ['areas' => [['id' => 'district', 'area' => ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2]]]];
    expect(fn() => MapAreaCatalog::readMap($map, $grid))->toThrow(InvalidArgumentException::class);
    $map['areas'][0]['area'] = ['cells' => [[1, 0], [0, 1]]];
    $catalog = MapAreaCatalog::readMap($map, $grid);
    expect($catalog->getArea('district')->cells)->toBe([[1, 0], [0, 1]])
        ->and(fn() => $catalog->getArea('removed'))->toThrow(InvalidArgumentException::class);
});

it('derives optional landmark discovery from the same area without driving story progress', function () {
    $grid = array_fill(0, 4, array_fill(0, 5, ' '));
    $map = ['areas' => [['id' => 'survey-reach', 'area' => ['cells' => [[1, 1], [3, 2]]]]],
        'landmarks' => [['id' => 'reach', 'kind' => 'landmark', 'anchor' => ['area' => 'survey-reach'],
            'name' => ['message' => 'test.survey-reach', 'default' => 'Survey Reach'], 'approachDistance' => 0]]];
    $catalog = MapLandmarkCatalog::readMap($map, $grid);
    expect(array_keys($map['landmarks'][0]['anchor']))->each->toBeIn(MapLandmarkCatalog::ANCHOR_KINDS)
        ->and($catalog->landmarks[0]->area->cells)->toBe([[1, 1], [3, 2]]);
    $state = new GameState();
    $knowledge = new MapKnowledge('synthetic/map', $catalog);
    $knowledge->discoverNear(2, 1, $state);
    expect($state->hasRevealedLandmark('synthetic/map', 'reach'))->toBeFalse();
    $knowledge->discoverNear(1, 1, $state);
    expect($state->hasRevealedLandmark('synthetic/map', 'reach'))->toBeTrue()
        ->and($state->hasStoryEvent('arrived'))->toBeFalse();
    $map['areas'] = [];
    expect(fn() => MapLandmarkCatalog::readMap($map, $grid))->toThrow(InvalidArgumentException::class);
});
