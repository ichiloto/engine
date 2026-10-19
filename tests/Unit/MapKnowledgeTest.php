<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\MapKnowledge;
use Ichiloto\Engine\Field\MapLandmarkCatalog;

function makeLandmarkMap(array $landmarks = []): array
{
    return ['events' => [
        ['marker' => 'A', 'class' => TransferPlayerTrigger::class,
            'area' => ['cells' => [[1, 1], [2, 1], [1, 2], [8, 3]]],
            'conditions' => [['type' => 'switch', 'name' => 'gate_open']],
            'data' => ['destinationMap' => 'never-leak-this-name', 'spawnPoint' => ['x' => 900, 'y' => 900]]],
        ['marker' => 'B', 'class' => TransferPlayerTrigger::class,
            'area' => ['cells' => [[9, 4]]], 'data' => ['destinationMap' => 'never-leak-this-name']],
    ], 'worldObjects' => [['id' => 'tower-sign', 'anchor' => ['x' => 5, 'y' => 2]]], 'landmarks' => $landmarks];
}

function makeLandmarkEntry(string $id, array $overrides = []): array
{
    return array_replace(['id' => $id, 'kind' => 'entrance', 'anchor' => ['event' => 'A'],
        'name' => ['message' => 'maps.test.gate', 'default' => 'Gate']], $overrides);
}

function readLandmarkTestMap(array $map): MapLandmarkCatalog
{
    return MapLandmarkCatalog::readMap($map, array_fill(0, 5, array_fill(0, 10, ' ')));
}

it('shows distinct anonymous physical transfers immediately without names or map visitation', function () {
    $state = new GameState();
    $knowledge = new MapKnowledge('test', readLandmarkTestMap(makeLandmarkMap()));
    $before = $state->toArray();
    $visible = $knowledge->getVisibleLandmarks($state);
    expect($visible)->toHaveCount(2)->and(array_column($visible, 'name'))->toBe([null, null])
        ->and(array_column($visible, 'accessible'))->toBe([false, true])
        ->and($visible[0]['definition']->getMarkerCells())->toHaveCount(2);
    foreach ($visible[0]['definition']->getMarkerCells() as $point) {
        expect($visible[0]['definition']->area->contains($point))->toBeTrue();
    }
    $knowledge->discoverNear(1, 1, $state);
    expect($state->toArray())->toBe($before);
});

it('learns names and buildings through approach and preserves them on reload and revisit', function () {
    $map = makeLandmarkMap([
        makeLandmarkEntry('gate', ['approachDistance' => 1]),
        makeLandmarkEntry('tower', ['kind' => 'building', 'anchor' => ['object' => 'tower-sign'], 'approachDistance' => 1]),
    ]);
    $catalog = readLandmarkTestMap($map);
    $state = new GameState();
    $knowledge = new MapKnowledge('test', $catalog);
    expect($knowledge->getVisibleLandmarks($state))->toHaveCount(2);
    $knowledge->discoverNear(0, 0, $state);
    expect($state->hasRevealedLandmark('test', 'gate'))->toBeFalse();
    $knowledge->discoverNear(0, 1, $state);
    $knowledge->discoverNear(5, 3, $state);
    $loaded = GameState::fromArray($state->toArray());
    $revisit = new MapKnowledge('test', $catalog);
    expect($revisit->getVisibleLandmarks($loaded))->toHaveCount(3)
        ->and($revisit->getVisibleLandmarks($loaded)[0]['name'])->toBe('Gate')
        ->and($loaded->hasRevealedLandmark('test', 'tower'))->toBeTrue()
        ->and($loaded->hasRevealedLandmark('another-map', 'tower'))->toBeFalse();
    // Moving the owning object changes placement without losing learned identity.
    $map['worldObjects'][0]['anchor'] = ['x' => 6, 'y' => 4];
    $moved = new MapKnowledge('test', readLandmarkTestMap($map));
    $tower = $moved->getVisibleLandmarks($loaded)[1]['definition'];
    expect($tower->getMarkerCells()[0]->x)->toBe(6.0)->and($tower->getMarkerCells()[0]->y)->toBe(4.0);
});

it('never reveals a secret by approach or access and latches explicit play revelation', function () {
    $entry = makeLandmarkEntry('secret-gate', ['secret' => true,
        'revealConditions' => [['type' => 'event', 'name' => 'heard_of_tunnel']]]);
    $knowledge = new MapKnowledge('test', readLandmarkTestMap(makeLandmarkMap([$entry])));
    $state = new GameState();
    $state->setSwitch('gate_open');
    $knowledge->discoverNear(1, 1, $state);
    expect($knowledge->getVisibleLandmarks($state))->toHaveCount(1)
        ->and($state->hasRevealedLandmark('test', 'secret-gate'))->toBeFalse();
    $state->recordStoryEvent('heard_of_tunnel');
    $knowledge->discoverNear(9, 4, $state);
    expect($knowledge->getVisibleLandmarks($state))->toHaveCount(2)
        ->and($knowledge->getVisibleLandmarks($state)[0]['name'])->toBe('Gate');
    $state->setSwitch('gate_open', false);
    expect($knowledge->getVisibleLandmarks($state)[0]['accessible'])->toBeFalse()
        ->and($knowledge->getVisibleLandmarks($state)[0]['name'])->toBe('Gate');
});

it('supports explicit revelations without approach and preserves snapshot rollback observers', function () {
    $knowledge = new MapKnowledge('test', readLandmarkTestMap(makeLandmarkMap([
        makeLandmarkEntry('hidden', ['secret' => true]),
    ])));
    $state = new GameState();
    $writes = [];
    $observer = function ($kind, $name) use (&$writes) { $writes[] = [$kind, $name]; };
    $state->onChange = $observer;
    $snapshot = $state->toArray();
    $knowledge->discoverNear(1, 1, $state);
    $state->revealLandmark('test', 'hidden');
    $state->revealLandmark('test', 'hidden');
    expect($knowledge->getVisibleLandmarks($state))->toHaveCount(2)->and($writes)->toBe([['landmark', 'test:hidden']]);
    $state->restoreSnapshot($snapshot);
    expect($knowledge->getVisibleLandmarks($state))->toHaveCount(1)->and($state->onChange)->toBe($observer);
});

it('loads old saves and refuses malformed knowledge as a secret revelation', function () {
    expect(GameState::fromArray([])->hasRevealedLandmark('test', 'secret'))->toBeFalse();
    $state = GameState::fromArray(['knownLandmarks' => ['test' => ['secret' => false, 'other' => 'true', 'good' => true], 'bad' => true]]);
    expect($state->hasRevealedLandmark('test', 'secret'))->toBeFalse()
        ->and($state->hasRevealedLandmark('test', 'other'))->toBeFalse()
        ->and($state->hasRevealedLandmark('test', 'good'))->toBeTrue();
});

it('validates anchors and discovery metadata before installation', function (array $entry) {
    expect(fn() => readLandmarkTestMap(makeLandmarkMap([$entry])))->toThrow(InvalidArgumentException::class);
})->with([
    'missing event' => [makeLandmarkEntry('gate', ['anchor' => ['event' => 'X']])],
    'missing object' => [makeLandmarkEntry('gate', ['kind' => 'building', 'anchor' => ['object' => 'absent']])],
    'duplicate coordinates' => [makeLandmarkEntry('gate', ['anchor' => ['x' => 1, 'y' => 1]])],
    'route uses object' => [makeLandmarkEntry('gate', ['anchor' => ['object' => 'tower-sign']])],
    'transfer hides ordinary route' => [makeLandmarkEntry('gate', ['kind' => 'building'])],
    'mistyped secret' => [makeLandmarkEntry('gate', ['secret' => 'true'])],
    'null secret' => [makeLandmarkEntry('gate', ['secret' => null])],
    'secret approach is dead data' => [makeLandmarkEntry('gate', ['secret' => true, 'approachDistance' => 1])],
    'negative approach' => [makeLandmarkEntry('gate', ['approachDistance' => -1])],
    'empty reveal conditions' => [makeLandmarkEntry('gate', ['revealConditions' => []])],
    'mistyped reveal condition' => [makeLandmarkEntry('gate', ['revealConditions' => [['type' => 'unknown', 'name' => 'secret']]])],
]);

it('refuses duplicate ids, duplicate event claims and off-map ragged anchors', function () {
    expect(fn() => readLandmarkTestMap(makeLandmarkMap([makeLandmarkEntry('gate'), makeLandmarkEntry('gate')])))->toThrow(InvalidArgumentException::class);
    expect(fn() => readLandmarkTestMap(makeLandmarkMap([makeLandmarkEntry('gate'), makeLandmarkEntry('other')])))->toThrow(InvalidArgumentException::class);
    expect(fn() => MapLandmarkCatalog::readMap(makeLandmarkMap(), [[' '], []]))->toThrow(InvalidArgumentException::class);
});

it('keeps legacy transfer anchors local and allows a stable secret declaration', function () {
    $map = ['triggers' => [['id' => 'back-door', 'destinationMap' => 'elsewhere',
        'trigger_area' => ['x' => 2, 'y' => 2], 'spawn_point' => ['x' => 100, 'y' => 200]]],
        'landmarks' => [makeLandmarkEntry('secret', ['anchor' => ['trigger' => 'back-door'], 'secret' => true])]];
    $state = new GameState();
    $knowledge = new MapKnowledge('legacy', readLandmarkTestMap($map));
    expect($knowledge->getVisibleLandmarks($state))->toBe([]);
    $state->revealLandmark('legacy', 'secret');
    $point = $knowledge->getVisibleLandmarks($state)[0]['definition']->getMarkerCells()[0];
    expect([$point->x, $point->y])->toBe([2.0, 2.0]);
});

it('validates unsaved Editor anchors identically and supports numeric event markers', function () {
    $map = makeLandmarkMap([makeLandmarkEntry('gate')]);
    $map['events'][0]['marker'] = '0';
    $map['landmarks'][0]['anchor'] = ['event' => '0'];
    $anchors = \Ichiloto\Engine\Field\MapLandmarkAnchors::readMap($map);
    $edited = MapLandmarkCatalog::readDeclarations($map['landmarks'], $anchors, [10, 10, 10, 10, 10]);
    expect($edited)->toEqual(readLandmarkTestMap($map));
    $key = MapLandmarkCatalog::getNameMessageKey('test/map', 'gate');
    expect(new \Ichiloto\Engine\Localization\LocalizedText($key, 'Gate'))->toBeInstanceOf(\Ichiloto\Engine\Localization\LocalizedText::class)
        ->and($key)->not->toBe(MapLandmarkCatalog::getNameMessageKey('test.map', 'gate'));
});
