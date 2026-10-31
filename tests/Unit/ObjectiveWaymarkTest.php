<?php

use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Animations\Field\FieldWaymarkStyle;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapRoute;
use Ichiloto\Engine\Field\MapRouteGraph;
use Ichiloto\Engine\Field\Waymarks\FieldWaymark;
use Ichiloto\Engine\Field\Waymarks\FieldWaymarkAnchor;
use Ichiloto\Engine\Field\Waymarks\FieldWaymarkLocatorInterface;
use Ichiloto\Engine\Field\Waymarks\FieldWaymarkResolver;
use Ichiloto\Engine\Field\Waymarks\FieldWaymarkRole;
use Ichiloto\Engine\Field\Waymarks\FieldWaymarkTerminalPresenter;
use Ichiloto\Engine\Quests\Quest;
use Ichiloto\Engine\Quests\QuestLog;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Quests\QuestWaymark;
use Ichiloto\Engine\Quests\QuestWaymarkSubject;

/**
 * Writes synthetic maps: each event is [x, y, definition] on a 20x10 grid.
 *
 * @param array<string, array{events?: array<string, array{0: int, 1: int, 2: array<string, mixed>}>, landmarks?: list<array<string, mixed>>}> $maps
 * @param array<string, list<array<string, mixed>>> $scripts Event scripts by id.
 */
function writeWaymarkProject(array $maps, array $scripts = []): string
{
  $root = createTestDirectory('ichiloto-waymarks-');
  foreach ($maps as $id => $map) {
    $directory = $root . '/Maps/' . $id;
    mkdir($directory, 0777, true);
    $leaf = basename($id);
    $layer = array_fill(0, 10, array_fill(0, 20, ' '));
    $events = [];
    foreach ($map['events'] ?? [] as $marker => [$x, $y, $event]) {
      $layer[$y][$x] = $marker;
      $events[$marker] = $event;
    }
    file_put_contents("{$directory}/{$leaf}.data.php", '<?php return ' . var_export(
      ['events' => $events, 'landmarks' => $map['landmarks'] ?? []], true) . ';');
    $rows = implode("\n", array_map(static fn(array $row): string => implode('', $row), $layer));
    file_put_contents("{$directory}/{$leaf}.event.php", "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n{$rows}\nICHILOTO_EVENT_MAP;\n");
  }
  if ($scripts !== []) {
    mkdir($root . '/Events', 0777, true);
    foreach ($scripts as $id => $commands) {
      file_put_contents("{$root}/Events/{$id}.php", '<?php return ' . var_export($commands, true) . ';');
    }
  }
  return $root;
}

function transferTo(string $map, array $conditions = []): array
{
  return array_filter(['class' => 'Ichiloto\\Engine\\Events\\Triggers\\TransferPlayerTrigger',
    'conditions' => $conditions, 'data' => ['destinationMap' => $map]]);
}

function scriptEvent(string $scriptId, bool $reusable): array
{
  return ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
    'data' => ['mode' => 'action', 'reusable' => $reusable, 'scriptId' => $scriptId]];
}

final class FakeWaymarkLocator implements FieldWaymarkLocatorInterface
{
  /** @param array<string, list<FieldWaymarkAnchor>> $anchors Keyed by "kind:id". */
  public function __construct(private array $anchors) {}

  public function locate(QuestWaymarkSubject $subject, string $id): array
  {
    return $this->anchors[$subject->value . ':' . $id] ?? [];
  }
}

function questWithWaymarks(string $id, array $objectives, bool $optional = false): Quest
{
  return Quest::fromArray(['id' => $id, 'name' => $id, 'optional' => $optional, 'objectives' => $objectives]);
}

/* Authored contract */

it('reads a waymark as a map with at most one stable subject and its own conditions', function () {
  $waymark = QuestWaymark::fromArray(['map' => 'garden', 'event' => 'H',
    'conditions' => [['type' => 'event', 'name' => 'tracks_recorded', 'negate' => true]]]);

  expect($waymark->map)->toBe('garden')
    ->and($waymark->subject)->toBe(QuestWaymarkSubject::EVENT)
    ->and($waymark->subjectId)->toBe('H')
    ->and($waymark->toArray())->toBe(['map' => 'garden', 'event' => 'H',
      'conditions' => [['type' => 'event', 'name' => 'tracks_recorded', 'negate' => true]]])
    ->and(QuestWaymark::fromArray(['map' => 'garden'])->subject)->toBeNull();
});

it('refuses waymarks without a map, with two subjects, unknown fields or invalid conditions', function (array $data) {
  expect(fn() => QuestWaymark::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with([
  'no map' => [['event' => 'H']],
  'two subjects' => [['map' => 'm', 'event' => 'H', 'npc' => 'jori']],
  'blank subject' => [['map' => 'm', 'npc' => ' ']],
  'raw coordinates' => [['map' => 'm', 'x' => 4, 'y' => 2]],
  'bad condition' => [['map' => 'm', 'conditions' => [['type' => 'weather', 'name' => 'rain']]]],
]);

it('keeps objectives without waymarks valid and refuses a malformed waymark list', function () {
  $quest = questWithWaymarks('plain', [['type' => 'flag', 'target' => 'done']]);

  expect($quest->objectives[0]->waymarks)->toBe([])
    ->and(fn() => questWithWaymarks('bad', [['type' => 'flag', 'target' => 'done', 'waymarks' => ['map' => 'm']]]))
    ->toThrow(InvalidArgumentException::class);
});

/* Leading to where a quest starts */

function questStartingAt(string $id, array $startWaymarks, array $prerequisites = [], bool $optional = false): Quest
{
  return Quest::fromArray(['id' => $id, 'name' => $id, 'optional' => $optional, 'prerequisites' => $prerequisites,
    'objectives' => [['type' => 'flag', 'target' => "{$id}_done"]], 'startWaymarks' => $startWaymarks]);
}

it('reads a quest\'s start waymarks and refuses a malformed list', function () {
  expect(questStartingAt('errand', [['map' => 'home', 'npc' => 'keeper']])->startWaymarks[0]->toArray())
    ->toBe(['map' => 'home', 'npc' => 'keeper'])
    ->and(questWithWaymarks('plain', [['type' => 'flag', 'target' => 'done']])->startWaymarks)->toBe([])
    ->and(fn() => questStartingAt('bad', ['map' => 'home']))->toThrow(InvalidArgumentException::class)
    ->and(fn() => questStartingAt('bad', [['map' => 'home', 'x' => 3]]))->toThrow(InvalidArgumentException::class);
});

it('shows a quest\'s start waymarks while it can start and each one\'s own conditions hold', function () {
  $quest = questStartingAt('errand', [
    ['map' => 'home', 'npc' => 'keeper'],
    ['map' => 'home', 'event' => 'N', 'conditions' => [['type' => 'event', 'name' => 'note_read', 'negate' => true]]],
  ], [['type' => 'event', 'name' => 'woke_up']]);
  $log = new QuestLog();
  $state = new GameState();

  // Its prerequisite does not hold yet.
  expect(QuestManager::selectStartWaymarks([$quest], $log, $state))->toBe([]);

  $state->recordStoryEvent('woke_up');
  $leads = QuestManager::selectStartWaymarks([$quest], $log, $state);
  expect(array_map(static fn($entry): string => $entry->waymark->subjectId, $leads))->toBe(['keeper', 'N'])
    ->and($leads[0]->objectiveIndex)->toBe(-1)
    ->and($leads[0]->getId())->toBe('errand:-1:0')
    ->and($leads[0]->isOptionalQuest)->toBeFalse()
    ->and($leads[0]->fillsGap)->toBeFalse()
    ->and($leads[0]->label)->toBe('Continue the story');

  $state->recordStoryEvent('note_read');
  expect(array_map(static fn($entry): string => $entry->waymark->subjectId, QuestManager::selectStartWaymarks([$quest], $log, $state)))
    ->toBe(['keeper']);

  // Once the quest starts, its objectives lead instead; once finished, nothing does.
  $log->accept('errand', 1);
  expect(QuestManager::selectStartWaymarks([$quest], $log, $state))->toBe([]);
  $log->markCompleted('errand');
  expect(QuestManager::selectStartWaymarks([$quest], $log, $state))->toBe([]);
});

it('marks a side quest\'s start waymark as optional, without naming the quest', function () {
  $leads = QuestManager::selectStartWaymarks([questStartingAt('lost-cat', [['map' => 'town', 'npc' => 'neighbour']], optional: true)],
    new QuestLog(), new GameState());

  expect($leads)->toHaveCount(1)
    ->and($leads[0]->isOptionalQuest)->toBeTrue()
    ->and($leads[0]->label)->toBe('Side Quest');
});

/* Which waymarks are current */

it('shows waymarks only for incomplete objectives of active quests', function () {
  $quest = questWithWaymarks('crosswind', [
    ['type' => 'flag', 'target' => 'arrived', 'waymarks' => [['map' => 'garden', 'event' => 'E']]],
    ['type' => 'flag', 'target' => 'found', 'waymarks' => [['map' => 'garden', 'npc' => 'jori']]],
  ]);
  $log = new QuestLog();
  $state = new GameState();

  expect(QuestManager::selectActiveWaymarks([$quest], $log, $state))->toBe([]);

  $log->accept('crosswind', 2);
  $log->setProgress('crosswind', 0, 1);
  $active = QuestManager::selectActiveWaymarks([$quest], $log, $state);

  expect($active)->toHaveCount(1)
    ->and($active[0]->waymark->subjectId)->toBe('jori')
    ->and($active[0]->label)->toBe('Trigger found')
    ->and($active[0]->getId())->toBe('crosswind:1:0');

  $log->markCompleted('crosswind');
  expect(QuestManager::selectActiveWaymarks([$quest], $log, $state))->toBe([]);
});

it('expresses ordered phases and free-order sets through each waymark\'s own conditions', function () {
  $readings = array_map(static fn(string $marker): array => ['map' => 'garden', 'event' => $marker,
    'conditions' => [['type' => 'event', 'name' => "reading_{$marker}", 'negate' => true]]], ['H', 'I', 'J']);
  $quest = questWithWaymarks('survey', [
    ['type' => 'flag', 'target' => 'readings_done', 'waymarks' => $readings],
    ['type' => 'flag', 'target' => 'reported', 'waymarks' => [['map' => 'post', 'npc' => 'rhea',
      'conditions' => [['type' => 'event', 'name' => 'readings_done']]]]],
  ]);
  $log = new QuestLog();
  $log->accept('survey', 2);
  $state = new GameState();
  $markers = static fn(): array => array_map(static fn($entry): string => $entry->waymark->subjectId,
    QuestManager::selectActiveWaymarks([$quest], $log, $state));

  expect($markers())->toBe(['H', 'I', 'J']);
  $state->recordStoryEvent('reading_I');
  expect($markers())->toBe(['H', 'J']);
  $state->recordStoryEvent('reading_H');
  $state->recordStoryEvent('reading_J');
  $state->recordStoryEvent('readings_done');
  $log->setProgress('survey', 0, 1);
  expect($markers())->toBe(['rhea']);
});

it('derives the same waymarks after a save round trip, with no saved waymark state', function () {
  $quest = questWithWaymarks('records', [
    ['type' => 'flag', 'target' => 'boundary', 'waymarks' => [['map' => 'office', 'event' => 'G']]],
    ['type' => 'flag', 'target' => 'warning', 'waymarks' => [['map' => 'office', 'event' => 'H']]],
  ], optional: true);
  $log = new QuestLog();
  $log->accept('records', 2);
  $log->setProgress('records', 1, 1);
  $state = new GameState();
  $state->recordStoryEvent('unrelated');
  $before = QuestManager::selectActiveWaymarks([$quest], $log, $state);
  $restoredState = new GameState();
  $restoredState->restoreSnapshot(json_decode(json_encode($state->toArray()), true));
  $after = QuestManager::selectActiveWaymarks([$quest],
    QuestLog::fromArray(json_decode(json_encode($log->toArray()), true)), $restoredState);

  expect($after)->toEqual($before)->and($after[0]->waymark->subjectId)->toBe('G')
    ->and($after[0]->isOptionalQuest)->toBeTrue()
    ->and($log->toArray())->not->toHaveKey('waymarks');
});

/* Routes between maps */

it('routes through transfers and reusable scripted transports, never one-shot story scripts', function () {
  $root = writeWaymarkProject([
    'annex' => ['events' => [
      'A' => [1, 1, transferTo('town')],
      'M' => [5, 5, scriptEvent('mine-transport', true)],
      'D' => [7, 7, scriptEvent('medical-review', false)],
    ]],
    'town' => ['events' => ['E' => [2, 2, transferTo('annex')]]],
    'mines' => ['events' => ['D' => [3, 3, transferTo('rift')]]],
    'rift' => ['events' => []],
  ], [
    'mine-transport' => [['type' => 'branch', 'conditions' => [['type' => 'event', 'name' => 'ready']],
      'then' => [['type' => 'common_event', 'id' => 'depart']], 'else' => [['type' => 'text', 'text' => 'Not yet.']]]],
    'depart' => [['type' => 'transfer', 'map' => 'mines', 'x' => 1, 'y' => 1]],
    'medical-review' => [['type' => 'transfer', 'map' => 'town', 'x' => 1, 'y' => 1]],
  ]);
  $graph = Ichiloto\Engine\Field\ProjectMapIndex::scan($root)->routes;
  $any = static fn(MapRoute $route): bool => true;

  expect(array_map(static fn(MapRoute $route): string => $route->marker . '>' . $route->destination, $graph->routes['annex']))
    ->toBe(['A>town', 'M>mines'])
    ->and($graph->findNextRoute('annex', 'rift', $any)?->marker)->toBe('M')
    ->and($graph->findNextRoute('annex', 'rift', $any)?->scripted)->toBeTrue()
    ->and($graph->findNextRoute('town', 'rift', $any)?->marker)->toBe('E')
    ->and($graph->findNextRoute('rift', 'annex', $any))->toBeNull()
    ->and($graph->findNextRoute('annex', 'annex', $any))->toBeNull();
});

it('prefers a plain passage over an equally short scripted transport and skips unusable routes', function () {
  $root = writeWaymarkProject([
    'road' => ['events' => [
      'C' => [1, 1, scriptEvent('wolf-return', true)],
      'A' => [9, 1, transferTo('annex')],
      'G' => [4, 4, transferTo('cellar', [['type' => 'event', 'name' => 'cellar_open']])],
    ]],
    'annex' => ['events' => []],
    'cellar' => ['events' => []],
  ], ['wolf-return' => [['type' => 'transfer', 'map' => 'annex', 'x' => 1, 'y' => 1]]]);
  $graph = Ichiloto\Engine\Field\ProjectMapIndex::scan($root)->routes;
  $state = new GameState();
  $canUse = static fn(MapRoute $route): bool => Ichiloto\Engine\Core\WorldConditionEvaluator::allHold($route->conditions, $state);

  expect($graph->findNextRoute('road', 'annex', $canUse)?->marker)->toBe('A')
    ->and($graph->findNextRoute('road', 'cellar', $canUse))->toBeNull();
  $state->recordStoryEvent('cellar_open');
  expect($graph->findNextRoute('road', 'cellar', $canUse)?->marker)->toBe('G');
});

it('records the landmark that claims a route so secret passages stay hidden until revealed', function () {
  $root = writeWaymarkProject([
    'hall' => ['events' => ['S' => [3, 3, transferTo('vault')]],
      'landmarks' => [['id' => 'hidden-door', 'kind' => 'passage', 'anchor' => ['event' => 'S'], 'secret' => true]]],
    'vault' => ['events' => []],
  ]);
  $route = Ichiloto\Engine\Field\ProjectMapIndex::scan($root)->routes->routes['hall'][0];

  expect($route->landmarkId)->toBe('hidden-door')->and($route->secret)->toBeTrue();
});

it('gates event and NPC waymarks by what their map authors: conditions, cue conditions and one-shot completion', function () {
  $root = writeWaymarkProject(['garden' => ['events' => [
    'H' => [2, 2, ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
      'conditions' => [['type' => 'event', 'name' => 'relay_open']],
      'cue' => ['symbol' => '!', 'color' => 'bright-yellow', 'conditions' => [['type' => 'event', 'name' => 'tracks_recorded', 'negate' => true]]],
      'data' => ['mode' => 'action', 'reusable' => true, 'scriptId' => 'tracks']]],
    'R' => [5, 5, ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
      'data' => ['mode' => 'action', 'reusable' => false, 'scriptId' => 'rite']]],
  ]]]);
  $data = require $root . '/Maps/garden/garden.data.php';
  $data['npcs'] = [['id' => 'jori', 'name' => 'Jori', 'sprite' => 'J', 'x' => 1, 'y' => 1,
    'conditions' => [['type' => 'event', 'name' => 'jori_arrived']]]];
  file_put_contents($root . '/Maps/garden/garden.data.php', '<?php return ' . var_export($data, true) . ';');
  $gates = Ichiloto\Engine\Field\ProjectMapIndex::scan($root)->gates;
  $state = new GameState();

  expect($gates->allowsEvent('garden', 'H', $state))->toBeFalse()
    ->and($gates->allowsEvent('garden', 'R', $state))->toBeTrue()
    ->and($gates->allowsNpc('garden', 'jori', $state))->toBeFalse()
    ->and($gates->allowsEvent('garden', 'missing', $state))->toBeFalse();
  $state->recordStoryEvent('relay_open');
  $state->recordStoryEvent('jori_arrived');
  $state->markEventComplete('garden', 'R');
  expect($gates->allowsEvent('garden', 'H', $state))->toBeTrue()
    ->and($gates->allowsNpc('garden', 'jori', $state))->toBeTrue()
    ->and($gates->allowsEvent('garden', 'R', $state))->toBeFalse();
  $state->recordStoryEvent('tracks_recorded');
  expect($gates->allowsEvent('garden', 'H', $state))->toBeFalse();
});

/* Resolving the active map */

it('marks subjects on this map and the next exit toward objectives elsewhere', function () {
  $graph = new MapRouteGraph(['annex' => [
    new MapRoute('annex', 'town', 'A', Ichiloto\Engine\Core\CellArea::fromArray(['x' => 19, 'y' => 4, 'width' => 1, 'height' => 2])),
  ]]);
  $quest = questWithWaymarks('q', [
    ['type' => 'talk_to', 'target' => 'Sera', 'waymarks' => [['map' => 'annex', 'npc' => 'sera']]],
    ['type' => 'reach_map', 'target' => 'town', 'waymarks' => [['map' => 'town']]],
    ['type' => 'flag', 'target' => 'shop', 'waymarks' => [['map' => 'town', 'event' => 'B']]],
    ['type' => 'flag', 'target' => 'here', 'waymarks' => [['map' => 'annex']]],
  ]);
  $log = new QuestLog();
  $log->accept('q', 4);
  $active = QuestManager::selectActiveWaymarks([$quest], $log, new GameState());
  $locator = new FakeWaymarkLocator(['npc:sera' => [new FieldWaymarkAnchor(new Vector2(4, 4), 'npc:sera')]]);
  $marks = FieldWaymarkResolver::resolve($active, 'annex', $locator, $graph, static fn(): bool => true);

  expect($marks)->toHaveCount(2)
    ->and($marks[0]->role)->toBe(FieldWaymarkRole::TARGET)
    ->and($marks[0]->anchor->objectId)->toBe('npc:sera')
    ->and($marks[1]->role)->toBe(FieldWaymarkRole::ROUTE)
    ->and($marks[1]->anchor->eventMarker)->toBe('A')
    ->and($marks[1]->destination)->toBe('town')
    ->and($marks[1]->label)->toBe('Reach town');
});

it('marks an exit that is itself a target once, as the target', function () {
  $graph = new MapRouteGraph([
    'annex' => [new MapRoute('annex', 'mines', 'M',
      Ichiloto\Engine\Core\CellArea::fromArray(['x' => 5, 'y' => 5, 'width' => 1, 'height' => 1]), scripted: true)],
    'mines' => [new MapRoute('mines', 'rift', 'D',
      Ichiloto\Engine\Core\CellArea::fromArray(['x' => 1, 'y' => 1, 'width' => 1, 'height' => 1]))],
  ]);
  $quest = questWithWaymarks('djin', [['type' => 'flag', 'target' => 'contract', 'waymarks' => [
    ['map' => 'annex', 'event' => 'M'], ['map' => 'rift', 'event' => 'B'],
  ]]]);
  $log = new QuestLog();
  $log->accept('djin', 1);
  $locator = new FakeWaymarkLocator(['event:M' => [new FieldWaymarkAnchor(new Vector2(5, 5), eventMarker: 'M')]]);
  $marks = FieldWaymarkResolver::resolve(QuestManager::selectActiveWaymarks([$quest], $log, new GameState()),
    'annex', $locator, $graph, static fn(): bool => true);

  expect($marks)->toHaveCount(1)->and($marks[0]->role)->toBe(FieldWaymarkRole::TARGET);
});

/* Terminal presentation */

function placeWaymark(Vector2 $cell, ?Vector2 $player = null, int $inset = 0, ?string $objectId = null,
  array $cued = [], ?string $marker = null): array
{
  $waymark = new FieldWaymark('w', FieldWaymarkRole::TARGET, new FieldWaymarkAnchor($cell, $objectId, $marker), 'Go');
  return FieldWaymarkTerminalPresenter::place([$waymark], new FieldWaymarkStyle(inset: $inset),
    static fn(Vector2 $world): Vector2 => $world, 40, 20, $player, $cued);
}

it('draws the waymark symbol on screen and floats it above a character', function () {
  expect(placeWaymark(new Vector2(10, 5))[0])->toMatchArray(['x' => 10, 'y' => 5])
    ->and(placeWaymark(new Vector2(10, 5))[0]['text'])->toContain('!')
    ->and(placeWaymark(new Vector2(10, 5), objectId: 'npc:a')[0])->toMatchArray(['x' => 10, 'y' => 4]);
});

it('lets an event\'s own cue stand in for its waymark while on screen', function () {
  expect(placeWaymark(new Vector2(10, 5), cued: ['H' => true], marker: 'H'))->toBe([])
    ->and(placeWaymark(new Vector2(90, 5), cued: ['H' => true], marker: 'H'))->toHaveCount(1);
});

it('hugs the view edge with an arrow pointing toward an off-screen target', function (int $x, int $y, string $arrow,
  string $edge) {
  $placement = placeWaymark(new Vector2($x, $y))[0];

  expect($placement['text'])->toContain($arrow)
    ->and($placement['x'])->toBeGreaterThanOrEqual(0)->toBeLessThan(40)
    ->and($placement['y'])->toBeGreaterThanOrEqual(0)->toBeLessThan(20)
    ->and(match ($edge) {
      'right' => $placement['x'] === 39, 'left' => $placement['x'] === 0,
      'top' => $placement['y'] === 0, 'bottom' => $placement['y'] === 19,
    })->toBeTrue();
})->with([
  'east' => [200, 10, '→', 'right'], 'west' => [-200, 10, '←', 'left'],
  'north' => [20, -200, '↑', 'top'], 'south' => [20, 200, '↓', 'bottom'],
  'north-east' => [100, -40, '↗', 'top'], 'south-west' => [-60, 60, '↙', 'bottom'],
]);

it('keeps arrows the style inset from the edge', function () {
  expect(placeWaymark(new Vector2(200, 10), inset: 2)[0]['x'])->toBe(37);
});

it('never moves a waymark glyph for the player: the player shows on a shared cell and the glyph returns in place', function (
  int $x, int $y) {
  $alone = placeWaymark(new Vector2($x, $y));
  [$cellX, $cellY] = [$alone[0]['x'], $alone[0]['y']];

  // Wherever the player stands, a glyph it does not share a cell with keeps its own.
  foreach ([[0, 0], [$cellX - 1, $cellY], [$cellX, $cellY + 1], [$cellX + 1, $cellY - 1], [20, 10]] as [$px, $py]) {
    if ([$px, $py] === [$cellX, $cellY]) { continue; }
    expect(placeWaymark(new Vector2($x, $y), new Vector2($px, $py)))->toBe($alone);
  }
  expect(placeWaymark(new Vector2($x, $y), new Vector2($cellX, $cellY)))->toBe([]);
})->with(['on screen' => [10, 5], 'east edge' => [200, 10], 'south edge' => [20, 200], 'north-east edge' => [100, -40]]);

it('reads an authored waymark style beside the cue bindings and refuses invalid styles', function () {
  $catalog = FieldPresentationCatalog::fromArray(['waymarks' => ['color' => 'bright-cyan', 'symbol' => '*', 'inset' => 1]],
    sys_get_temp_dir());

  expect($catalog->waymarks->color)->toBe('bright-cyan')
    ->and($catalog->waymarks->symbol)->toBe('*')
    ->and($catalog->waymarks->inset)->toBe(1)
    ->and(FieldPresentationCatalog::fromArray([], sys_get_temp_dir())->waymarks)->toEqual(new FieldWaymarkStyle())
    ->and(fn() => FieldWaymarkStyle::fromArray(['symbol' => '!!']))->toThrow(InvalidArgumentException::class)
    ->and(fn() => FieldWaymarkStyle::fromArray(['inset' => 9]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => FieldWaymarkStyle::fromArray(['arrows' => []]))->toThrow(InvalidArgumentException::class);
});

/* Local and world maps */

function makeWaymarkAtlasSnapshot(array $waymarks): Ichiloto\Engine\UI\Presentation\MapAtlasSnapshot
{
  return new Ichiloto\Engine\UI\Presentation\MapAtlasSnapshot('Annex', 'map',
    new Ichiloto\Engine\Field\MapOverview(array_fill(0, 8, array_fill(0, 20, '.'))), [], [], 2, 2,
    Ichiloto\Engine\Core\Enumerations\MovementHeading::EAST, waymarks: $waymarks);
}

it('marks objectives and the way to them on the opened local map, with legend entries', function () {
  $theme = new Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog('/tmp', ['schema' => 'ichiloto.menu/1']);
  $canvas = Ichiloto\Engine\UI\Presentation\MapAtlasPresentation::compose(makeWaymarkAtlasSnapshot([
    new FieldWaymark('t', FieldWaymarkRole::TARGET, new FieldWaymarkAnchor(new Vector2(12, 3)), 'Find Jori'),
    new FieldWaymark('r', FieldWaymarkRole::ROUTE, new FieldWaymarkAnchor(new Vector2(19, 5), eventMarker: 'A'), 'Reach town'),
  ]), $theme, 1350, 720, Ichiloto\Engine\UI\Presentation\MapAtlasSize::OPENED);
  $indicators = array_column($canvas->indicators, null, 'id');
  $legend = implode("\n", array_map(static fn($run) => $run->text,
    array_column($canvas->textLayers, null, 'id')['atlas-legend']->runs));

  expect($indicators)->toHaveKeys(['atlas-waymark-0', 'atlas-waymark-1'])
    ->and($indicators['atlas-waymark-0']->color)->toEqual($theme->colors['objective'])
    ->and($legend)->toContain('Objective')->toContain('Way to objective')
    ->and(Ichiloto\Engine\UI\Presentation\MapAtlasPresentation::listIconRoles(new Ichiloto\Engine\Field\MapCategoryCatalog()))
    ->toContain('map.objective', 'map.objective.route');

  $plain = Ichiloto\Engine\UI\Presentation\MapAtlasPresentation::compose(makeWaymarkAtlasSnapshot([]), $theme, 1350, 720,
    Ichiloto\Engine\UI\Presentation\MapAtlasSize::OPENED);
  expect(array_filter(array_column($plain->indicators, 'id'), static fn(string $id) => str_starts_with($id, 'atlas-waymark')))->toBe([]);
});

it('draws the waymark symbol on the terminal local map', function () {
  $overview = new Ichiloto\Engine\Field\MapOverview(array_fill(0, 8, array_fill(0, 20, '.')));
  $rows = Ichiloto\Engine\UI\Presentation\MapAtlasTerminal::renderMap($overview, [], new Vector2(1, 1),
    Ichiloto\Engine\Core\Enumerations\MovementHeading::EAST, 20, 8,
    waymarks: [new FieldWaymark('t', FieldWaymarkRole::TARGET, new FieldWaymarkAnchor(new Vector2(12, 3)), 'Go')]);

  expect(Ichiloto\Engine\IO\Console\TerminalText::stripAnsi($rows[3]))->toContain('!')
    ->and(Ichiloto\Engine\IO\Console\TerminalText::stripAnsi($rows[5]))->not->toContain('!');
});

function makeWorldAtlasDocument(array $landmarks, array $events = []): Ichiloto\Engine\Field\MapAtlasDocument
{
  $grid = array_fill(0, 10, array_fill(0, 20, ' '));
  $resolved = [];
  foreach ($events as $marker => [$x, $y]) {
    $resolved[] = ['marker' => $marker, 'class' => 'Ichiloto\\Engine\\Events\\Triggers\\TransferPlayerTrigger',
      'area' => ['x' => $x, 'y' => $y, 'width' => 1, 'height' => 1], 'data' => ['destinationMap' => 'elsewhere']];
  }
  $data = ['events' => $resolved, 'landmarks' => $landmarks];
  return new Ichiloto\Engine\Field\MapAtlasDocument('world', ['data' => $data, 'worldObjects' => []],
    new Ichiloto\Engine\Field\MapOverview(array_fill(0, 10, array_fill(0, 20, '.'))),
    Ichiloto\Engine\Field\MapLandmarkCatalog::readMap($data, $grid), '/tmp');
}

it('marks a place on the world map only while its entry is visible to the player', function () {
  $document = makeWorldAtlasDocument([
    ['id' => 'garden', 'kind' => 'entrance', 'anchor' => ['event' => 'G']],
    ['id' => 'ruins', 'kind' => 'entrance', 'anchor' => ['event' => 'R'], 'secret' => true],
  ], ['G' => [4, 4], 'R' => [9, 2], 'T' => [15, 6]]);
  $quest = questWithWaymarks('q', [
    ['type' => 'flag', 'target' => 'a', 'waymarks' => [['map' => 'garden/post', 'npc' => 'rhea']]],
    ['type' => 'flag', 'target' => 'b', 'waymarks' => [['map' => 'ruins/hall']]],
    ['type' => 'flag', 'target' => 'c', 'waymarks' => [['map' => 'world', 'event' => 'T']]],
  ]);
  $log = new QuestLog();
  $log->accept('q', 3);
  $state = new GameState();
  $resolve = static fn(string $map): array => ['map' => 'world', 'landmark' => explode('/', $map)[0]];
  $cells = static fn(array $marks): array => array_map(static fn($mark) => [$mark->anchor->cell->x, $mark->anchor->cell->y], $marks);
  $active = QuestManager::selectActiveWaymarks([$quest], $log, $state);

  expect($cells(Ichiloto\Engine\Field\Waymarks\MapAtlasWaymarks::find($document, $active, $resolve, $state)))
    ->toEqual([[4, 4], [15, 6]]);
  $state->revealLandmark('world', 'ruins');
  expect($cells(Ichiloto\Engine\Field\Waymarks\MapAtlasWaymarks::find($document, $active, $resolve, $state)))
    ->toEqual([[4, 4], [9, 2], [15, 6]]);
});
