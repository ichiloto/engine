<?php

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\InteractionReach;
use Ichiloto\Engine\Events\Triggers\ChestEventTrigger;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\MapSourceReader;
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
