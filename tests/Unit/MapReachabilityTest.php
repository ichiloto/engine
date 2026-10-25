<?php

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\InteractionReach;
use Ichiloto\Engine\Events\Triggers\ChestEventTrigger;
use Ichiloto\Engine\Events\Enumerations\EventEngagement;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\MapSourceReader;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\NpcPlacement;
use Ichiloto\Engine\Field\Reachability\MapReachability;
use Ichiloto\Engine\Field\Reachability\ProjectReachability;
use Ichiloto\Engine\Field\Reachability\ReachabilityEntrance;
use Ichiloto\Engine\Field\Reachability\ReachabilityEvent;
use Ichiloto\Engine\Field\Reachability\ReachabilityProblem;
use Ichiloto\Engine\Field\Reachability\ReachabilityProblemKind;

/**
 * Builds a collision grid from rows: `#` is solid, anything else floor.
 *
 * @param string[] $rows
 * @return int[][]
 */
function reachabilityGrid(array $rows): array
{
  return array_map(static fn(string $row): array => array_map(
    static fn(string $cell): int => match ($cell) {
      '#' => CollisionType::SOLID->value,
      '=' => CollisionType::COUNTER->value,
      default => CollisionType::NONE->value,
    },
    str_split($row),
  ), $rows);
}

/** @param array<string, mixed> $entry */
function reachabilityNpc(array $entry): NpcPlacement
{
  return NpcPlacement::fromArray([...['name' => 'Someone', 'dialogue' => [['text' => 'Hi.']]], ...$entry]);
}

/** @return list<string> Problem kinds and messages, for readable failures. */
function reachabilityProblems(array $problems): array
{
  return array_map(static fn(ReachabilityProblem $problem): string => $problem->kind->value . ': ' . $problem->message, $problems);
}

// A room east of a one-cell doorway at (2, 1):
//   #####
//   ....#    entrance (0, 1); doorway (2, 1)
//   ##.##
//   #...#    the chest E at (2, 3)
//   #####
function reachabilityRoom(array $npcs): MapReachability
{
  return new MapReachability(
    'room',
    reachabilityGrid(['#####', '....#', '##.##', '#...#', '#####']),
    [new ReachabilityEvent(CellArea::fromRect(new Rect(2, 3, 1, 1)), ChestEventTrigger::class, 'E')],
    [],
    $npcs,
  );
}

it('validates reachable approaches to solid object events with their allowed headings', function (array $facing, bool $reachable) {
  $event = ReachabilityEvent::fromDefinition(['class' => ChestEventTrigger::class, 'marker' => 'C',
    'area' => ['cells' => [[1, 0]]], 'engagement' => 'facing', 'facing' => $facing]);
  $map = new MapReachability('object-room', reachabilityGrid(['###', '...']), [$event]);
  $report = $map->analyze([new ReachabilityEntrance(0, 1, 'door')]);
  expect($report->isReachable(1, 0))->toBeFalse()
    ->and($report->problems === [])->toBe($reachable);
})->with(['all approaches' => [[], true], 'from below' => [['North'], true], 'from above' => [['South'], false]]);

it('answers whether an event can be used from the cells it answers on, not from its own solid cells', function (array $facing, bool $usable) {
  // A two-cell desk on solid cells, open floor below and an enclosed nook above: the player never stands on it.
  $desk = ReachabilityEvent::fromDefinition(['class' => ChestEventTrigger::class, 'marker' => 'D',
    'area' => ['cells' => [[1, 1], [2, 1]]], 'engagement' => 'facing', 'facing' => $facing]);
  $map = new MapReachability('desk-room', reachabilityGrid(['#..#', '####', '....']), [$desk]);
  $report = $map->analyze([new ReachabilityEntrance(0, 2, 'door')]);
  expect($report->isAnyReachable($desk->area))->toBeFalse()
    ->and($report->canEngage($desk))->toBe($usable)
    ->and($report->problems === [])->toBe($usable);
})->with([
  'from any side' => [[], true],
  'from the floor below' => [['North'], true],
  'only from the enclosed nook above' => [['South'], false],
]);

it('reaches a faced object whose own walkable cell is the only approach', function () {
  // The object sits at the end of a one-cell-wide dead end: no adjacent cell faces it, but it can be stood on.
  $event = new ReachabilityEvent(CellArea::fromCells([[1, 0]]), ChestEventTrigger::class, 'C',
    EventEngagement::FACING, [MovementHeading::SOUTH]);
  $report = new MapReachability('walkable-object', reachabilityGrid(['#.#', '#.#']), [$event])
    ->analyze([new ReachabilityEntrance(1, 1, 'door')]);
  expect($event->getStandingArea()->containsCell(1, 0))->toBeTrue()
    ->and($report->problems)->toBe([]);
});

it('treats the walkable cells of a faced transfer as an exit', function () {
  $transfer = new ReachabilityEvent(CellArea::fromCells([[1, 1]]), TransferPlayerTrigger::class, 'T', EventEngagement::FACING);
  $chest = new ReachabilityEvent(CellArea::fromCells([[1, 2]]), ChestEventTrigger::class, 'C');
  // The only way to the chest passes over the transfer's walkable cell, which leaves the map first.
  $report = new MapReachability('walkable-transfer', reachabilityGrid(['#.#', '#.#', '#.#']), [$transfer, $chest])
    ->analyze([new ReachabilityEntrance(1, 0, 'door')]);
  expect($report->problems)->not->toBe([]);
});

it('rejects distant or enclosed object events despite nearby floor or area gaps', function () {
  $event = new ReachabilityEvent(CellArea::fromCells([[1, 0], [3, 0]]), ChestEventTrigger::class,
    'C', EventEngagement::FACING);
  $report = new MapReachability('sealed-objects', reachabilityGrid(['#####', '##.##', '##.##']), [$event])
    ->analyze([new ReachabilityEntrance(2, 2, 'door')]);
  expect($report->problems)->toHaveCount(1)
    ->and($report->problems[0]->kind)->toBe(ReachabilityProblemKind::UNREACHABLE_EVENT);
});

it('collects transfer and sleep arrivals from reachable object approaches', function () {
  $assets = createTestDirectory('object-reachability-project-') . '/assets';
  mkdir($assets . '/Data', 0777, true);
  writeReachabilityMap($assets, 'room', ['##..', '..#.'], ['ST  ', '    '], ['events' => [
    'S' => ['class' => \Ichiloto\Engine\Events\Triggers\SleepEventTrigger::class, 'engagement' => 'facing',
      'data' => ['spawnPoint' => ['x' => 3, 'y' => 1], 'spawnSprite' => ['South'], 'confirmDialogue' => ['text' => 'Rest?']]],
    'T' => ['class' => TransferPlayerTrigger::class, 'engagement' => 'facing',
      'data' => ['destinationMap' => 'destination', 'spawnPoint' => ['x' => 0, 'y' => 0], 'spawnSprite' => ['South']]],
  ]]);
  writeReachabilityMap($assets, 'destination', ['..'], ['  '], []);
  file_put_contents($assets . '/Maps/collisions.php', '<?php return ["." => \Ichiloto\Engine\Events\Enumerations\CollisionType::NONE, "#" => \Ichiloto\Engine\Events\Enumerations\CollisionType::SOLID];');
  file_put_contents($assets . '/Data/system.php', '<?php return ' . var_export([
    'startingPositions' => ['player' => ['destinationMap' => 'room', 'spawnPoint' => ['x' => 0, 'y' => 1]]],
  ], true) . ';');
  $report = ProjectReachability::analyze($assets);
  expect($report->getAllProblems())->toBe([])
    ->and($report->reports['room']->isReachable(3, 1))->toBeTrue()
    ->and($report->reports['destination']->isReachable(1, 0))->toBeTrue();
});

it('moves NPCs freely and reports only the one that blocks the way', function (array $npc, bool $isBlocked) {
  $report = reachabilityRoom([reachabilityNpc($npc)])->analyze([new ReachabilityEntrance(0, 1, 'the door')]);
  $kinds = array_map(static fn(ReachabilityProblem $problem): ReachabilityProblemKind => $problem->kind, $report->problems);

  expect(in_array(ReachabilityProblemKind::UNREACHABLE_EVENT, $kinds, true))->toBe($isBlocked, implode("\n", reachabilityProblems($report->problems)))
    ->and($report->isReachable(2, 3))->toBe(! $isBlocked);
})->with([
  'beside the route' => [['x' => 1, 'y' => 3], false],
  'in the doorway' => [['x' => 2, 'y' => 2], true],
  'wandering through the doorway' => [['x' => 2, 'y' => 2, 'movement' => 'wander'], false],
  // A story gate: present only until the condition holds, so assumed open.
  'a conditional gate in the doorway' => [['x' => 2, 'y' => 2, 'conditions' => [['type' => 'event', 'name' => 'gate_opened', 'negate' => true]]], false],
]);

it('lets an NPC stand on an event that keeps a free cell', function () {
  $map = new MapReachability(
    'shop',
    reachabilityGrid(['.....', '.....']),
    [new ReachabilityEvent(CellArea::fromRect(new Rect(1, 1, 3, 1)), ChestEventTrigger::class, 'B')],
    [],
    [reachabilityNpc(['x' => 2, 'y' => 1])],
  );

  expect($map->analyze([new ReachabilityEntrance(0, 0, 'the door')])->problems)->toBe([]);
});

it('reports a talkable NPC nobody can stand beside', function () {
  // Behind a counter row with no way round.
  $map = new MapReachability('inn', reachabilityGrid(['.....', '#####', '.....']), [], [], [
    reachabilityNpc(['name' => 'Keeper', 'x' => 2, 'y' => 2]),
    reachabilityNpc(['name' => 'Silent', 'x' => 4, 'y' => 2, 'dialogue' => []]),
  ]);

  expect(reachabilityProblems($map->analyze([new ReachabilityEntrance(0, 0, 'the door')])->problems))->toBe([
    'unreachable_npc: NPC Keeper at (2, 2) can never be spoken to: no reachable cell is beside it or directly across one counter cell from it.',
  ]);
});

it('removes multi-cell-counter reach from map analysis while preserving one-cell reach', function (array $rows, bool $isSpokenTo) {
  // The keeper stands at (2, 0); the player arrives at (2, 4).
  $map = new MapReachability('shop', reachabilityGrid($rows), [], [], [reachabilityNpc(['name' => 'Keeper', 'x' => 2, 'y' => 0])]);
  $report = $map->analyze([new ReachabilityEntrance(2, 4, 'the door')]);

  expect($report->canSpeakTo(2, 0))->toBe($isSpokenTo)
    ->and($report->problems === [])->toBe($isSpokenTo)
    // The player never walks onto a counter.
    ->and($report->isReachable(2, 1))->toBeFalse();
})->with([
  'one counter' => [['#...#', '##=##', '#...#', '#...#', '#...#'], true],
  'two counters deep removed' => [['#...#', '##=##', '##=##', '#...#', '#...#'], false],
  'three counters deep removed' => [['#...#', '##=##', '##=##', '##=##', '#...#'], false],
  'floor gap behind the counter' => [['#...#', '#...#', '##=##', '#...#', '#...#'], false],
  'a wall behind the counter' => [['#...#', '##=##', '##=##', '#####', '#...#'], false],
]);

it('stops the reach at another NPC standing on the counter', function () {
  $map = new MapReachability('shop', reachabilityGrid(['#.#', '#=#', '#=#', '#.#']), [], [], [
    reachabilityNpc(['name' => 'Keeper', 'x' => 1, 'y' => 0]),
    reachabilityNpc(['name' => 'Cat', 'x' => 1, 'y' => 2, 'dialogue' => []]),
  ]);

  expect($map->analyze([new ReachabilityEntrance(1, 3, 'the door')])->canSpeakTo(1, 0))->toBeFalse()
    ->and(InteractionReach::findTalkCell(1, 3, 0, -1,
      static fn(int $x, int $y): bool => in_array([$x, $y], [[1, 1], [1, 2]], true),
      static fn(int $x, int $y): bool => [$x, $y] === [1, 2] || [$x, $y] === [1, 0]))->toBe([1, 2]);
});

it('uses the same direct or one-cell counter rule for reachable standing cells in all headings', function (array $direction, int $depth) {
  [$dx, $dy] = $direction;
  [$x, $y] = [4, 4];
  $grid = array_fill(0, 9, array_fill(0, 9, CollisionType::SOLID->value));
  $grid[$y][$x] = CollisionType::NONE->value;
  for ($distance = 1; $distance <= $depth; $distance++) {
    $grid[$y + $dy * $distance][$x + $dx * $distance] = CollisionType::COUNTER->value;
  }
  [$nx, $ny] = [$x + $dx * ($depth + 1), $y + $dy * ($depth + 1)];
  $grid[$ny][$nx] = CollisionType::NONE->value;
  $report = new MapReachability('synthetic-counter', $grid, npcs: [reachabilityNpc(['x' => $nx, 'y' => $ny])])
    ->analyze([new ReachabilityEntrance($x, $y, 'synthetic entrance')]);
  expect($report->canSpeakTo($nx, $ny))->toBe($depth <= 1)
    ->and($report->isReachable($nx, $ny))->toBeFalse()
    ->and(array_column($report->problems, 'kind'))->toBe($depth <= 1 ? [] : [ReachabilityProblemKind::UNREACHABLE_NPC]);
})->with(['north' => [[0, -1]], 'east' => [[1, 0]], 'south' => [[0, 1]], 'west' => [[-1, 0]]])
  ->with(['direct' => [0], 'one counter' => [1], 'two counters removed' => [2], 'three counters removed' => [3]]);

it('uses one-cell counter reach at the project authoring boundary before allowing an NPC-gated arrival', function (int $depth, bool $layered) {
  $root = createTestDirectory('counter-authoring-');
  $assets = $root . '/assets';
  mkdir($assets . '/Data', 0700, true);
  $rows = ['#.#', ...array_fill(0, $depth, '#=#'), '#.#'];
  writeReachabilityMap($assets, 'service', $rows, array_fill(0, count($rows), '   '), [
    'npcs' => [['name' => 'Synthetic Keeper', 'x' => 1, 'y' => 0, 'script' => [
      ['type' => 'transfer', 'map' => 'destination', 'x' => 0, 'y' => 0],
    ]]],
  ]);
  writeReachabilityMap($assets, 'destination', ['..'], ['  '], []);
  $dictionary = ['.' => CollisionType::NONE, '#' => CollisionType::SOLID, '=' => CollisionType::COUNTER];
  if ($layered) {
    $directory = $assets . '/Maps/service/layers';
    mkdir($directory);
    $terrain = array_map(static fn(string $row): string => str_replace('=', '.', $row), $rows);
    $fixtures = array_map(static fn(string $row): string => str_replace(['#', '.'], ' ', $row), $rows);
    $front = array_fill(0, count($rows), '   ');
    $front[array_key_last($front)] = ' # ';
    foreach (['00.terrain.map.php' => $terrain, '01.fixtures.map.php' => $fixtures, '02.front.deco.php' => $front] as $file => $grid) {
      file_put_contents($directory . '/' . $file, "<?php\nreturn <<<'GRID'\n" . implode("\n", $grid) . "\nGRID;\n");
    }
    // The layer owns COUNTER; a decorative overhang never adds another collision row.
    $dictionary['='] = CollisionType::SOLID;
    $dictionary['fixtures'] = ['=' => CollisionType::COUNTER];
  }
  file_put_contents($assets . '/Maps/collisions.php', '<?php return ' . var_export($dictionary, true) . ';');
  file_put_contents($assets . '/Data/system.php', '<?php return ' . var_export([
    'startingPositions' => ['player' => ['destinationMap' => 'service', 'spawnPoint' => ['x' => 1, 'y' => $depth + 1]]],
  ], true) . ';');
  $project = ProjectReachability::analyze($assets);
  expect($project->reports['service']->canSpeakTo(1, 0))->toBe($depth <= 1)
    ->and($project->reports['destination']->isReachable(0, 0))->toBe($depth <= 1)
    ->and(array_column($project->reports['service']->problems, 'kind'))
    ->toBe($depth <= 1 ? [] : [ReachabilityProblemKind::UNREACHABLE_NPC])
    ->and(array_column($project->reports['destination']->problems, 'kind'))
    ->toBe($depth <= 1 ? [] : [ReachabilityProblemKind::NO_ENTRANCE]);
})->with([0, 1, 2, 3])->with(['flat source' => [false], 'fixtures layer with decorative front' => [true]]);

it('reaches a transfer but never walks through it', function () {
  // The corridor's only way east is through the transfer at (2, 0).
  $map = new MapReachability(
    'hall',
    reachabilityGrid(['.....']),
    [
      new ReachabilityEvent(CellArea::fromRect(new Rect(2, 0, 1, 1)), TransferPlayerTrigger::class, 'A'),
      new ReachabilityEvent(CellArea::fromRect(new Rect(4, 0, 1, 1)), ChestEventTrigger::class, 'C'),
    ],
  );
  $report = $map->analyze([new ReachabilityEntrance(0, 0, 'the west door')]);

  expect($report->isReachable(2, 0))->toBeTrue()
    ->and($report->isReachable(3, 0))->toBeFalse()
    ->and(reachabilityProblems($report->problems))->toBe([
      'unreachable_event: No cell of event C (ChestEventTrigger) at (4, 0) can be reached, so it never fires.',
    ]);

  // Arriving on a transfer is fine: the player only leaves by stepping onto one.
  expect($map->analyze([new ReachabilityEntrance(2, 0, 'the east door')])->isReachable(4, 0))->toBeTrue();
});

it('reports arrivals outside the map or on a blocked cell, and floods from a blocked one', function () {
  $map = new MapReachability('field', reachabilityGrid(['#..', '...']));

  $outside = $map->analyze([new ReachabilityEntrance(9, 9, 'a stale transfer')]);
  $onWall = $map->analyze([new ReachabilityEntrance(0, 0, 'a transfer')]);

  expect(reachabilityProblems($outside->problems))->toBe([
    'blocked_entrance: The player arrives at (9, 9) from a stale transfer, outside the map.',
  ])->and($outside->isReachable(1, 0))->toBeFalse()
    ->and(reachabilityProblems($onWall->problems))->toBe([
      'blocked_entrance: The player arrives at (0, 0) from a transfer, on a cell the player cannot stand on.',
    ])->and($onWall->isReachable(2, 1))->toBeTrue();
});

it('notes a map nothing brings the player onto yet, without calling it a blocker', function () {
  $problems = new MapReachability('vestige', reachabilityGrid(['..']))->analyze([])->problems;

  expect(reachabilityProblems($problems))->toBe([
    'no_entrance: Nothing the player can reach from the start brings them onto it yet.',
  ])->and($problems[0]->kind->isBlocking())->toBeFalse()
    ->and(ReachabilityProblemKind::UNREACHABLE_EVENT->isBlocking())->toBeTrue();
});

/**
 * Writes a map in the split format the field reads.
 *
 * @param string[] $rows Gameplay rows.
 * @param string[] $events Event-layer rows.
 * @param array<string, mixed> $data
 */
function writeReachabilityMap(string $assets, string $mapId, array $rows, array $events, array $data): void
{
  $directory = "{$assets}/Maps/{$mapId}";
  $leaf = basename($mapId);
  mkdir($directory, 0777, true);
  file_put_contents("{$directory}/{$leaf}.map.php", "<?php\nreturn <<<'MAP'\n" . implode("\n", $rows) . "\nMAP;\n");
  file_put_contents("{$directory}/{$leaf}.event.php", "<?php\nreturn <<<'EVENT'\n" . implode("\n", $events) . "\nEVENT;\n");
  file_put_contents("{$directory}/{$leaf}.data.php", '<?php return ' . var_export($data, true) . ';');
}

function removeReachabilityProject(string $directory): void
{
  foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
  }

  rmdir($directory);
}

it('checks unsaved current maps and new maps without copying sources or losing subject ownership', function () {
  $root = createTestDirectory('in-memory-reachability-');
  $assets = $root . '/assets';
  mkdir($assets . '/Data', 0700, true);
  $open = array_fill(0, 3, CollisionType::NONE);
  writeReachabilityMap($assets, 'hub', ['...'], ['   '], ['occupancy' => [$open]]);
  file_put_contents($assets . '/Data/system.php', '<?php return ' . var_export([
    'startingPositions' => ['player' => ['destinationMap' => 'hub', 'spawnPoint' => ['x' => 0, 'y' => 0], 'spawnSprite' => ['South']]],
  ], true) . ';');
  $paths = MapSourceReader::resolvePaths($assets . '/Maps', 'hub');
  $before = array_map(file_get_contents(...), array_intersect_key($paths, array_flip(['data', 'map', 'event'])));
  $layers = new MapLayerSet([new MapLayer('ground', 0, false, 'memory/ground', '...')]);
  $sources = [
    'hub' => ['layers' => $layers, 'eventLayer' => [[' ', ' ', 'A']], 'data' => ['occupancy' => [$open], 'events' => [
      'A' => ['class' => TransferPlayerTrigger::class, 'data' => ['destinationMap' => 'new/room', 'spawnPoint' => ['x' => 0, 'y' => 0], 'spawnSprite' => ['South']]],
    ]]],
    'new/room' => ['layers' => $layers, 'eventLayer' => [[' ', ' ', ' ']], 'data' => ['occupancy' => [$open],
      'worldObjects' => [['id' => 'fixture-1', 'anchor' => ['x' => 2, 'y' => 0], 'pivot' => ['x' => .5, 'y' => 1.], 'sprites2d' => null]],
      'events' => ['F' => ['class' => ChestEventTrigger::class, 'subject' => ['kind' => 'world_object', 'id' => 'fixture-1'],
        'engagement' => 'facing', 'data' => []]],
    ]],
  ];
  $input = serialize($sources);
  $project = ProjectReachability::analyze($assets, $sources);
  expect(array_keys($project->reports))->toBe(['hub', 'new/room'])
    ->and($project->getAllProblems())->toBe([])->and($project->reports['new/room']->isReachable(1, 0))->toBeTrue()
    ->and(serialize($sources))->toBe($input)->and(is_dir($assets . '/Maps/new'))->toBeFalse();
  $sources['new/room']['data']['occupancy'][0][1] = CollisionType::SOLID;
  $blocked = ProjectReachability::analyze($assets, $sources);
  expect($blocked->reports['new/room']->isReachable(1, 0))->toBeFalse()
    ->and(array_column($blocked->getAllProblems(), 'kind'))->toContain(ReachabilityProblemKind::UNREACHABLE_EVENT);
  $sources['new/room'] = null;
  $removed = ProjectReachability::analyze($assets, $sources);
  expect(array_keys($removed->reports))->toBe(['hub'])
    ->and(array_column($removed->getAllProblems(), 'kind'))->toContain(ReachabilityProblemKind::UNKNOWN_DESTINATION);
  expect(array_map(file_get_contents(...), array_intersect_key($paths, array_flip(['data', 'map', 'event']))))->toBe($before)
    ->and(array_keys(ProjectReachability::analyze($assets)->reports))->toBe(['hub']);
});

it('reports invalid in-memory sources instead of falling back to the older saved map', function (array $change) {
  $root = createTestDirectory('invalid-memory-map-');
  $assets = $root . '/assets';
  writeReachabilityMap($assets, 'room', ['...'], ['   '], ['occupancy' => [[CollisionType::NONE, CollisionType::NONE, CollisionType::NONE]]]);
  $source = array_replace(['data' => [], 'eventLayer' => [[' ', ' ', ' ']],
    'layers' => new MapLayerSet([new MapLayer('ground', 0, false, 'memory', '...')])], $change);
  $project = ProjectReachability::analyze($assets, ['room' => $source]);
  expect($project->reports)->toBe([])->and($project->problems)->toHaveCount(1)
    ->and($project->problems[0]->kind)->toBe(ReachabilityProblemKind::UNREADABLE_MAP)
    ->and($project->problems[0]->mapId)->toBe('room');
})->with([
  [['eventLayer' => [[' ']]]],
  [['data' => ['events' => ['X' => ['subject' => ['kind' => 'world_object', 'id' => 'absent']]]]]],
  [['layers' => 'not layers']],
  [['unexpected' => true]],
]);

it('checks declared physical occupancy independently of glyphs and the legacy dictionary', function (bool $dictionaryPresent) {
  $root = createTestDirectory('physical-reachability-');
  $assets = $root . '/assets';
  mkdir($assets . '/Data', 0700, true);
  writeReachabilityMap($assets, 'physical', ['###'], ['   '], [
    'occupancy' => [[CollisionType::NONE, CollisionType::NONE, CollisionType::SOLID]],
  ]);
  if ($dictionaryPresent) {
    file_put_contents($assets . '/Maps/collisions.php', '<?php return ["#" => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::SOLID];');
  }
  file_put_contents($assets . '/Data/system.php', '<?php return ' . var_export([
    'startingPositions' => ['player' => ['destinationMap' => 'physical', 'spawnPoint' => ['x' => 0, 'y' => 0]]],
  ], true) . ';');
  $project = ProjectReachability::analyze($assets);
  expect($project->getAllProblems())->toBe([])
    ->and($project->reports['physical']->isReachable(1, 0))->toBeTrue()
    ->and($project->reports['physical']->isReachable(2, 0))->toBeFalse();
})->with(['conflicting legacy dictionary' => [true], 'no legacy dictionary' => [false]]);

it('reports malformed declared occupancy instead of checking glyph-derived collisions', function () {
  $root = createTestDirectory('invalid-physical-reachability-');
  $assets = $root . '/assets';
  writeReachabilityMap($assets, 'physical', ['..'], ['  '], ['occupancy' => null]);
  file_put_contents($assets . '/Maps/collisions.php', '<?php return ["." => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::NONE];');
  $project = ProjectReachability::analyze($assets);
  expect($project->reports)->toBe([])
    ->and($project->problems)->toHaveCount(1)
    ->and($project->problems[0]->kind)->toBe(ReachabilityProblemKind::UNREADABLE_MAP)
    ->and($project->problems[0]->message)->toContain('Map occupancy must be a zero-based list');
});

it('reaches maps only through arrivals the player can get to from the start', function () {
  $root = sys_get_temp_dir() . '/ichiloto-reachability-' . bin2hex(random_bytes(4));
  $assets = "{$root}/assets";
  mkdir("{$assets}/Data", 0777, true);
  mkdir("{$assets}/Events", 0777, true);
  writeReachabilityMap($assets, 'town', ['.....#.', '..#..#.'], ['A      ', ' S     '], [
    'events' => [
      'A' => ['class' => TransferPlayerTrigger::class, 'data' => ['destinationMap' => 'town/house', 'spawnPoint' => ['x' => 1, 'y' => 1], 'spawnSprite' => ['South']]],
      'S' => ['class' => \Ichiloto\Engine\Events\Triggers\SleepEventTrigger::class, 'data' => [
        'spawnPoint' => ['x' => 4, 'y' => 0], 'spawnSprite' => ['South'], 'confirmDialogue' => ['name' => 'Keeper', 'text' => 'Rest?'], 'cost' => 0,
      ]],
    ],
    'npcs' => [['name' => 'Guide', 'x' => 2, 'y' => 0, 'dialogue' => [['lines' => [['text' => 'This way.']], 'script' => [['type' => 'transfer', 'map' => 'nowhere', 'x' => 0, 'y' => 0]]]]]],
  ]);
  writeReachabilityMap($assets, 'town/house', ['...', '...'], ['   ', '   '], []);
  writeReachabilityMap($assets, 'ruins', ['..'], ['  '], []);
  // An island: its door leads into town, but nothing leads to it.
  writeReachabilityMap($assets, 'island', ['..'], ['A '], ['events' => [
    'A' => ['class' => TransferPlayerTrigger::class, 'data' => ['destinationMap' => 'town', 'spawnPoint' => ['x' => 6, 'y' => 0], 'spawnSprite' => ['South']]],
  ]]);
  file_put_contents("{$assets}/Maps/collisions.php", "<?php\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\nreturn ['.' => CollisionType::NONE, '#' => CollisionType::SOLID, ' ' => CollisionType::NONE];\n");
  file_put_contents("{$assets}/Data/system.php", "<?php\nreturn ['startingPositions' => ['player' => ['destinationMap' => 'town', 'spawnPoint' => ['x' => 0, 'y' => 1], 'spawnSprite' => ['South']]]];\n");
  file_put_contents("{$assets}/Events/return-home.php", "<?php\nreturn [['type' => 'text', 'text' => 'Home.'], ['type' => 'branch', 'then' => [['type' => 'transfer', 'map' => 'ruins', 'x' => 0, 'y' => 0]]]];\n");

  try {
    $project = ProjectReachability::analyze($assets);

    expect(array_keys($project->reports))->toBe(['island', 'ruins', 'town', 'town/house'])
      ->and(reachabilityProblems($project->getAllProblems()))->toBe([
        'unknown_destination: a scripted transfer by NPC Guide on town sends the player to map "nowhere", which the project does not have or cannot read.',
        'no_entrance: Nothing the player can reach from the start brings them onto it yet.',
      ])
      ->and($project->getAllProblems()[1]->mapId)->toBe('island')
      // The guide and the wall cut the town in two: the island's door into the
      // east side does not count, but the reachable sleep event's spawn does.
      ->and($project->reports['town']->isReachable(4, 1))->toBeTrue()
      // The sealed strip only the island's door leads into stays out of reach.
      ->and($project->reports['town']->isReachable(6, 0))->toBeFalse()
      ->and($project->reports['town/house']->isReachable(2, 1))->toBeTrue()
      ->and($project->reports['ruins']->isReachable(1, 0))->toBeTrue();

    // A missing collision dictionary leaves nothing readable, and says so.
    unlink("{$assets}/Maps/collisions.php");
    expect(reachabilityProblems(ProjectReachability::analyze($assets)->getAllProblems())[0] ?? '')
      ->toStartWith('unreadable_map: The collision dictionary could not be read');
  } finally {
    removeReachabilityProject($root);
  }
});

it('wakes the party where an innkeeper script says, on the map running it', function () {
  $root = sys_get_temp_dir() . '/ichiloto-reachability-' . bin2hex(random_bytes(4));
  $assets = "{$root}/assets";
  mkdir("{$assets}/Data", 0777, true);
  mkdir("{$assets}/Events", 0777, true);
  $inn = static fn(array $spawnPoint): array => ['type' => 'inn', 'confirmDialogue' => ['text' => 'Rest?'], 'spawnPoint' => $spawnPoint];
  // The wall seals the east strip; only the stay wakes the party there.
  writeReachabilityMap($assets, 'inn', ['...#.', '...#.'], ['     ', '     '], [
    'npcs' => [
      ['name' => 'Keeper', 'x' => 1, 'y' => 0, 'script' => [$inn(['x' => 4, 'y' => 1])]],
      ['name' => 'Porter', 'x' => 2, 'y' => 0, 'script' => [['type' => 'inn', 'confirmDialogue' => ['text' => 'Rest?']], $inn(['x' => 3, 'y' => 1])]],
    ],
  ]);
  file_put_contents("{$assets}/Maps/collisions.php", "<?php\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\nreturn ['.' => CollisionType::NONE, '#' => CollisionType::SOLID, ' ' => CollisionType::NONE];\n");
  file_put_contents("{$assets}/Data/system.php", "<?php\nreturn ['startingPositions' => ['player' => ['destinationMap' => 'inn', 'spawnPoint' => ['x' => 0, 'y' => 1], 'spawnSprite' => ['South']]]];\n");
  // A shared script runs on whichever map calls it, so its stay names no map.
  file_put_contents("{$assets}/Events/rest.php", '<?php return ' . var_export([$inn(['x' => 9, 'y' => 9])], true) . ';');

  try {
    $project = ProjectReachability::analyze($assets);

    expect($project->reports['inn']->isReachable(4, 0))->toBeTrue()
      ->and(reachabilityProblems($project->getAllProblems()))->toBe([
        'blocked_entrance: The player arrives at (3, 1) from a scripted inn stay by NPC Porter on inn, on a cell the player cannot stand on.',
      ]);
  } finally {
    removeReachabilityProject($root);
  }
});

it('reads a door marker painted in two places as one event on exactly those cells', function () {
  $root = sys_get_temp_dir() . '/ichiloto-reachability-' . bin2hex(random_bytes(4));
  $assets = "{$root}/assets";
  mkdir("{$assets}/Data", 0777, true);
  // D leaves the town by the east edge and again by a south gap; the walled
  // cells between them are not part of the door.
  writeReachabilityMap($assets, 'town', ['.....', '.#..D', '.#...', '##.##'], ['     ', '    D', '     ', '  D  '], [
    'events' => ['D' => ['class' => TransferPlayerTrigger::class, 'data' => ['destinationMap' => 'field', 'spawnPoint' => ['x' => 0, 'y' => 0], 'spawnSprite' => ['South']]]],
  ]);
  writeReachabilityMap($assets, 'field', ['..'], ['  '], []);
  file_put_contents("{$assets}/Maps/collisions.php", "<?php\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\nreturn ['.' => CollisionType::NONE, '#' => CollisionType::SOLID, 'D' => CollisionType::NONE, ' ' => CollisionType::NONE];\n");
  file_put_contents("{$assets}/Data/system.php", "<?php\nreturn ['startingPositions' => ['player' => ['destinationMap' => 'town', 'spawnPoint' => ['x' => 0, 'y' => 0], 'spawnSprite' => ['South']]]];\n");

  try {
    $source = MapSourceReader::readFiles(MapSourceReader::resolvePaths("{$assets}/Maps", 'town'));
    $project = ProjectReachability::analyze($assets);

    expect($source['data']['events'][0]['area'])->toBe(['cells' => [[4, 1], [2, 3]]])
      ->and(reachabilityProblems($project->getAllProblems()))->toBe([])
      ->and($project->reports['field']->isReachable(1, 0))->toBeTrue()
      // A door cell is reached but not walked through; the cells between the
      // two placements are ordinary floor.
      ->and($project->reports['town']->isReachable(4, 1))->toBeTrue()
      ->and($project->reports['town']->isReachable(3, 2))->toBeTrue();
  } finally {
    removeReachabilityProject($root);
  }
});

// An office entered through a door at (1, 0); the officer stands at (3, 2)
// behind a two-cell desk at (3, 3)-(4, 3):
//   #.#####
//   #.....#
//   #..@..#
//   #..==.#
//   #.....#
//   #.....#
//   #######
function approachOffice(array $event, array $npcConditions = []): MapReachability
{
  $definition = [...['class' => \Ichiloto\Engine\Events\Triggers\ScriptEventTrigger::class, 'marker' => 'C',
    'area' => ['cells' => [[3, 3], [4, 3]]], 'engagement' => 'facing', 'speakers' => ['officer'],
    'data' => ['mode' => 'action', 'scriptId' => 'inspection']], ...$event];

  return new MapReachability('office', reachabilityGrid(['#.#####', '#.....#', '#.....#', '#..==.#', '#.....#', '#.....#', '#######']),
    [ReachabilityEvent::fromDefinition($definition)], [],
    [reachabilityNpc(['id' => 'officer', 'name' => 'Officer', 'x' => 3, 'y' => 2, 'conditions' => $npcConditions])]);
}

it('reports an interaction the player reaches from behind and must walk round to use', function () {
  $problems = approachOffice(['facing' => ['North']])->findDetouredApproaches([new ReachabilityEntrance(1, 0, 'the north door')]);

  expect(reachabilityProblems($problems))->toBe([
    'detoured_approach: Arriving from the north door, the player first reaches event C (ScriptEventTrigger) at (3, 3) on (3, 1), beside its speaker Officer, where it does not answer, and walks 3 more steps round to (3, 4) to use it.',
  ])->and($problems[0]->kind->isBlocking())->toBeFalse();
});

it('accepts an interaction met on a side where it answers, or within one step of it', function (array $event, int $x, int $y) {
  expect(approachOffice($event)->findDetouredApproaches([new ReachabilityEntrance($x, $y, 'a door')]))->toBe([]);
})->with([
  'arriving in front of the desk' => [['facing' => ['North']], 1, 5],
  'answering from every side' => [['facing' => []], 1, 0],
  'facing the arrival path' => [['facing' => ['South'], 'speakers' => []], 1, 0],
]);

it('skips automatic events and arrivals that cannot happen while the event runs', function () {
  $office = approachOffice(['facing' => ['North'], 'data' => ['mode' => 'auto', 'scriptId' => 'inspection']]);
  $gated = approachOffice(['facing' => ['North'], 'conditions' => [['type' => 'event', 'name' => 'inspected', 'negate' => true]]]);

  expect($office->findDetouredApproaches([new ReachabilityEntrance(1, 0, 'the north door')]))->toBe([])
    ->and($gated->findDetouredApproaches([new ReachabilityEntrance(1, 0, 'the north door after inspection',
      [['type' => 'event', 'name' => 'inspected']])]))->toBe([])
    ->and($gated->findDetouredApproaches([new ReachabilityEntrance(1, 0, 'the north door')]))->toHaveCount(1);
});

it('reads a speaker absent while the event runs as neither contact nor obstacle', function () {
  $problems = approachOffice(['facing' => ['North'], 'conditions' => [['type' => 'event', 'name' => 'opened']]],
    [['type' => 'event', 'name' => 'opened', 'negate' => true]])->findDetouredApproaches([new ReachabilityEntrance(1, 0, 'the north door')]);

  // Without the officer the player stands on her cell, at the desk's empty north side, and still walks round it.
  expect(reachabilityProblems($problems))->toBe([
    'detoured_approach: Arriving from the north door, the player first reaches event C (ScriptEventTrigger) at (3, 3) on (3, 2), where it does not answer, and walks 2 more steps round to (3, 4) to use it.',
  ]);
});

it('reports a detoured approach through the project analysis, naming the arrival', function () {
  $assets = createTestDirectory('approach-reachability-project-') . '/assets';
  mkdir($assets . '/Data', 0777, true);
  writeReachabilityMap($assets, 'office', ['#.#####', '#.....#', '#.....#', '#..==.#', '#.....#', '#######'],
    ['       ', '       ', '       ', '   CC  ', '       ', '       '], ['events' => ['C' => [
      'class' => \Ichiloto\Engine\Events\Triggers\DialogueEventTrigger::class, 'engagement' => 'facing', 'facing' => ['North'],
      'data' => ['dialogue' => [['text' => 'Ready.']]],
    ]]]);
  file_put_contents($assets . '/Maps/collisions.php', '<?php return ["." => \Ichiloto\Engine\Events\Enumerations\CollisionType::NONE, "#" => \Ichiloto\Engine\Events\Enumerations\CollisionType::SOLID, "=" => \Ichiloto\Engine\Events\Enumerations\CollisionType::COUNTER];');
  file_put_contents($assets . '/Data/system.php', '<?php return ' . var_export([
    'startingPositions' => ['player' => ['destinationMap' => 'office', 'spawnPoint' => ['x' => 1, 'y' => 0]]],
  ], true) . ';');

  expect(reachabilityProblems(ProjectReachability::analyze($assets)->getAllProblems()))->toBe([
    'detoured_approach: Arriving from the starting position, the player first reaches event C (DialogueEventTrigger) at (3, 3) on (3, 2), where it does not answer, and walks 2 more steps round to (3, 4) to use it.',
  ]);
});

it('finds the shortest walk round walls and NPCs, turning as seldom as it can', function () {
  //   ......
  //   .###..
  //   ...N..   a fixed NPC at (3, 2)
  $map = new MapReachability('yard', reachabilityGrid(['......', '.###..', '......']), [], [], [reachabilityNpc(['x' => 3, 'y' => 2])]);
  $route = $map->findRoute([0, 2], [5, 2]);

  expect(count($route) - 1)->toBe(9)
    ->and($route[0])->toBe([0, 2])
    ->and(end($route))->toBe([5, 2])
    ->and(array_any($route, static fn(array $cell): bool => $cell === [3, 2] || $cell[1] === 1 && $cell[0] >= 1 && $cell[0] <= 3))->toBeFalse();
  // Each step moves one cell in one heading.
  foreach (array_slice($route, 1) as $index => [$x, $y]) {
    [$px, $py] = $route[$index];
    expect(abs($x - $px) + abs($y - $py))->toBe(1);
  }
  // Up the west side, along the top and down the east side: three turns, never a staircase.
  expect($route)->toBe([[0, 2], [0, 1], [0, 0], [1, 0], [2, 0], [3, 0], [4, 0], [4, 1], [4, 2], [5, 2]]);
});

it('walks to a transfer but never through one, and starts from one the player arrives on', function () {
  $map = new MapReachability('hall', reachabilityGrid(['.....']),
    [new ReachabilityEvent(CellArea::fromRect(new Rect(2, 0, 1, 1)), TransferPlayerTrigger::class, 'A')]);

  expect($map->findRoute([0, 0], [2, 0]))->toBe([[0, 0], [1, 0], [2, 0]])
    ->and($map->findRoute([0, 0], [4, 0]))->toBeNull()
    ->and($map->findRoute([2, 0], [4, 0]))->toBe([[2, 0], [3, 0], [4, 0]])
    ->and($map->isLeavingMapAt(2, 0))->toBeTrue()
    ->and($map->isLeavingMapAt(3, 0))->toBeFalse();
});

it('says why the player cannot stand on a cell', function () {
  $map = new MapReachability('room', reachabilityGrid(['.#.']), [], [], [reachabilityNpc(['x' => 2, 'y' => 0])]);

  expect($map->describeStandingProblem(0, 0))->toBeNull()
    ->and($map->describeStandingProblem(1, 0))->toBe('on a cell the player cannot stand on')
    ->and($map->describeStandingProblem(2, 0))->toBe('where an NPC stands')
    ->and($map->describeStandingProblem(9, 0))->toBe('outside the map')
    ->and($map->findRoute([0, 0], [2, 0]))->toBeNull()
    ->and($map->findRoute([0, 0], [0, 0]))->toBe([[0, 0]]);
});

it('reads a map source as the project analysis does', function () {
  $map = MapReachability::readMap('hall', [
    'events' => [['class' => TransferPlayerTrigger::class, 'marker' => 'A', 'area' => ['cells' => [[1, 0]]]]],
    'npcs' => [['name' => 'Guard', 'x' => 2, 'y' => 1, 'dialogue' => [['text' => 'Halt.']]]],
  ], reachabilityGrid(['...', '...']));

  expect($map->isLeavingMapAt(1, 0))->toBeTrue()
    ->and($map->describeStandingProblem(2, 1))->toBe('where an NPC stands')
    ->and($map->findRoute([0, 0], [1, 0]))->toBe([[0, 0], [1, 0]])
    // The door leaves the map and the guard stands in the only other way east.
    ->and($map->findRoute([0, 0], [2, 0]))->toBeNull();
});
