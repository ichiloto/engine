<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Events\MapEventDefinition;
use Ichiloto\Engine\Events\Triggers\CinematicEventTrigger;
use Ichiloto\Engine\Events\Triggers\DialogueEventTrigger;
use Ichiloto\Engine\Events\Triggers\EventTriggerFactory;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Field\MapSourceReader;
use Ichiloto\Engine\Field\Validation\MapEventResolver;

function makeSubjectMap(): array
{
    return ['worldObjects' => [[
        'id' => 'furniture-1', 'anchor' => ['x' => 3, 'y' => 2],
        'covers' => ['layer' => 'fixtures', 'cells' => [[2, 2], [3, 2], [3, 1]], 'tileLayers' => []],
        'conditions' => [['type' => 'switch', 'name' => 'subject_present', 'value' => true]],
        // Semantic area resolution deliberately needs no graphical resource or image.
        'resource' => 'replaceable-art',
    ]], 'events' => ['F' => ['class' => DialogueEventTrigger::class, 'subject' => ['kind' => 'world_object', 'id' => 'furniture-1'],
        'engagement' => 'facing', 'conditions' => [['type' => 'switch', 'name' => 'may_inspect', 'value' => true]], 'data' => []]]];
}

it('binds the exact authored cells and preserves event identity as objects and artwork change', function () {
    $grid = array_fill(0, 5, array_fill(0, 7, ' '));
    $map = makeSubjectMap();
    $event = EventTriggerFactory::create(MapSourceReader::resolveEvents($map, $grid)[0], 'synthetic/map');
    expect($event->marker)->toBe('F')->and($event->mapId)->toBe('synthetic/map');
    expect($event->canEngage(new Vector2(2, 3), MovementHeading::NORTH))->toBeTrue()
        ->and($event->canEngage(new Vector2(2, 1), MovementHeading::EAST))->toBeTrue()
        ->and($event->canEngage(new Vector2(1, 1), MovementHeading::EAST))->toBeFalse();
    $map['worldObjects'][0]['resource'] = 'new-art-with-another-size';
    $map['worldObjects'][0]['pivot'] = ['irrelevant' => 'to physical cells'];
    expect(MapSourceReader::resolveEvents($map, $grid)[0]['area'])->toBe($event->area->toArray());
    $map['worldObjects'][0]['anchor'] = ['x' => 5, 'y' => 3];
    $map['worldObjects'][0]['covers']['cells'] = [[4, 3], [5, 3], [5, 2]];
    $moved = EventTriggerFactory::create(MapSourceReader::resolveEvents($map, $grid)[0], 'synthetic/map');
    expect($moved->marker)->toBe('F')->and($moved->area->containsCell(2, 2))->toBeFalse()
        ->and($moved->area->containsCell(4, 3))->toBeTrue()->and($moved->area->containsCell(4, 2))->toBeFalse();
    unset($map['worldObjects'][0]['covers']);
    expect(MapSourceReader::resolveEvents($map, $grid)[0]['area']['cells'])->toBe([[5, 3]]);
});

it('shares subject and event conditions across save hydration without writing state', function () {
    $grid = array_fill(0, 5, array_fill(0, 7, ' '));
    $definition = MapSourceReader::resolveEvents(makeSubjectMap(), $grid)[0];
    $state = new GameState();
    $trigger = EventTriggerFactory::create($definition, 'synthetic/map');
    $trigger->bind($state);
    expect($trigger->isAvailable())->toBeFalse();
    $state->setSwitch('subject_present', true);
    expect($trigger->isAvailable())->toBeFalse();
    $state->setSwitch('may_inspect', true);
    expect($trigger->isAvailable())->toBeTrue();
    $saved = $state->toArray();
    $restored = GameState::fromArray($saved);
    $revisited = EventTriggerFactory::create($definition, 'synthetic/map');
    $revisited->bind($restored);
    expect($revisited->isAvailable())->toBeTrue()->and($restored->toArray())->toBe($saved);
    $restored->setSwitch('subject_present', false);
    expect($revisited->isAvailable())->toBeFalse();
});

it('rejects missing subjects, stale independent markers and invalid footprints before use', function (Closure $change) {
    $map = makeSubjectMap();
    $grid = array_fill(0, 5, array_fill(0, 7, ' '));
    $change($map, $grid);
    $resolution = MapEventResolver::inspect($map, $grid, 'synthetic/map');
    expect($resolution->issues)->not->toBeEmpty()
        ->and($resolution->issues[0]->mapId)->toBe('synthetic/map')
        ->and($resolution->issues[0]->rule)->toBe('event.subject')
        ->and($resolution->issues[0]->marker)->toBe('F');
    expect(fn() => $resolution->requireEvents())->toThrow(InvalidArgumentException::class);
})->with([
    'removed object' => [function (&$map, &$grid) { $map['worldObjects'] = []; }],
    'duplicate identity' => [function (&$map, &$grid) { $map['worldObjects'][] = $map['worldObjects'][0]; }],
    'wrong kind' => [function (&$map, &$grid) { $map['events']['F']['subject']['kind'] = 'texture'; }],
    'null subject' => [function (&$map, &$grid) { $map['events']['F']['subject'] = null; }],
    'stale painted cell' => [function (&$map, &$grid) { $grid[0][0] = 'F'; }],
    'independent area' => [function (&$map, &$grid) { $map['events']['F']['area'] = ['cells' => [[0, 0]]]; }],
    'out of map' => [function (&$map, &$grid) { $map['worldObjects'][0]['covers']['cells'] = [[99, 0]]; }],
    'duplicate cell' => [function (&$map, &$grid) { $map['worldObjects'][0]['covers']['cells'] = [[2, 2], [2, 2]]; }],
    'null conditions' => [function (&$map, &$grid) { $map['worldObjects'][0]['conditions'] = null; }],
]);

it('retains disconnected legacy marker cells and reports every invalid bound event', function () {
    $grid = array_fill(0, 5, array_fill(0, 7, ' '));
    $map = makeSubjectMap();
    unset($map['events']['F']['subject']);
    $grid[0][0] = $grid[4][6] = 'F';
    expect(MapSourceReader::resolveEvents($map, $grid)[0]['area']['cells'])->toBe([[0, 0], [6, 4]]);
    $map['events']['A'] = $map['events']['B'] = makeSubjectMap()['events']['F'];
    $map['worldObjects'] = [];
    $result = MapEventResolver::inspect($map, $grid, 'synthetic/map');
    expect(array_column($result->issues, 'marker'))->toEqualCanonicalizing(['A', 'B']);
});

it('exports a complete event envelope for subject, marker and legacy-area authoring', function (string $class, string $ownership) {
    $map = makeSubjectMap();
    $grid = array_fill(0, 5, array_fill(0, 7, ' '));
    $definition = $map['events']['F'];
    $definition['class'] = $class;
    $definition['data'] = match ($class) {
        ScriptEventTrigger::class => ['script' => [['type' => 'wait', 'duration' => 0]], 'mode' => ScriptEventTrigger::MODE_ACTION, 'reusable' => false],
        CinematicEventTrigger::class => ['cinematicId' => 'synthetic-scene', 'mode' => 'action', 'reusable' => false],
        default => ['dialogue' => []],
    };
    $definition['whenBlocked'] = 'Inspect the surroundings first.';
    $definition['sets'] = [['type' => 'event', 'name' => 'inspection_complete']];
    $definition['cue'] = ['symbol' => '!', 'color' => 'yellow'];
    $definition['facing'] = ['North'];
    if ($ownership !== 'subject') {
        unset($definition['subject']);
        if ($ownership === 'legacy area') {
            $definition['area'] = ['cells' => [[3, 2]]];
        } else {
            $grid[2][3] = 'F';
        }
    }
    if (in_array($ownership, ['indexed marker', 'legacy area'], true)) {
        $definition['marker'] = 'F';
        $map['events'] = [$definition];
    } else {
        $map['events'] = ['F' => $definition];
    }
    $rootFields = $class === CinematicEventTrigger::class
        ? CinematicCommandSchema::CINEMATIC_TRIGGER_ROOT_FIELDS : MapEventDefinition::ROOT_FIELDS;
    expect(array_diff(array_keys($definition), $rootFields))->toBe([]);
    $resolved = MapSourceReader::resolveEvents($map, $grid)[0];
    expect(array_diff(array_keys($resolved), $rootFields))->toBe([]);
    $event = EventTriggerFactory::create($resolved, 'synthetic/map');
    expect($event)->toBeInstanceOf($class)
        ->and($event->marker)->toBe('F')
        ->and($event->area->containsCell(3, 2))->toBeTrue()
        ->and($event->facing)->toBe([MovementHeading::NORTH])
        ->and($event->cue?->symbol)->toBe('!')
        ->and($event->sets)->toBe($definition['sets']);
})->with([DialogueEventTrigger::class, ScriptEventTrigger::class, CinematicEventTrigger::class])
    ->with(['subject', 'keyed marker', 'indexed marker', 'legacy area']);

it('exports script payloads and modes that retain inline and referenced script behavior', function (string $mode) {
    $root = createTestDirectory('ichiloto-script-definition-');
    mkdir($root . '/assets/Events', 0700, true);
    $script = [['type' => 'wait', 'duration' => 0]];
    file_put_contents($root . '/assets/Events/inspection.php', '<?php return ' . var_export($script, true) . ';');
    $previous = getcwd();
    chdir($root);
    try {
        foreach (['scriptId' => 'inspection', 'script' => $script] as $field => $value) {
            $data = [$field => $value, 'mode' => $mode, 'reusable' => false];
            expect(array_diff(array_keys($data), ScriptEventTrigger::DATA_FIELDS))->toBe([]);
            $event = EventTriggerFactory::create(['class' => ScriptEventTrigger::class,
                'area' => ['cells' => [[3, 2]]], 'data' => $data]);
            expect($event->script)->toBe($script)
                ->and($event->runsAutomatically())->toBe($mode === ScriptEventTrigger::MODE_AUTO)
                ->and($event->isReusable)->toBeFalse();
        }
    } finally {
        chdir($previous);
    }
})->with(ScriptEventTrigger::MODES);
