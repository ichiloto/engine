<?php

namespace Ichiloto\Engine\Field;

use Assegai\Util\Path;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Field\Enumerations\CompassDirection;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * How a region's places connect.
 *
 * Built from the maps themselves: every map names itself and its region, and
 * every `TransferPlayerTrigger` on it names where that door leads, which is
 * exactly the graph a player wants when they ask "where am I, and what is
 * next door?". Nothing has to be authored twice, and a new door drawn on a
 * map shows up on the region map by existing.
 *
 * @package Ichiloto\Engine\Field
 */
class RegionMap
{
  /**
   * @var array<string, RegionArea>|null The scanned areas, keyed by map id.
   */
  protected static ?array $areas = null;

  /**
   * Returns every area the project defines.
   *
   * The scan runs once per session; maps are small, but there is no reason to
   * re-read them every time the player opens the map.
   *
   * @return array<string, RegionArea> The areas, keyed by map id.
   */
  public static function areas(): array
  {
    return self::$areas ??= self::scan();
  }

  /**
   * Forgets the scanned areas.
   *
   * @return void
   */
  public static function reset(): void
  {
    self::$areas = null;
  }

  /**
   * Reads the maps under a specific directory instead of the project's.
   *
   * For tooling (and tests) that works on a project other than the one the
   * process was started in.
   *
   * @param string $root The directory holding the map files.
   * @return void
   */
  public static function loadFrom(string $root): void
  {
    self::$areas = self::scan($root);
  }

  /**
   * Returns the areas of one region.
   *
   * @param string $region The region name.
   * @return array<string, RegionArea> The region's areas, keyed by map id.
   */
  public static function inRegion(string $region): array
  {
    return array_filter(
      self::areas(),
      static fn(RegionArea $area): bool => strcasecmp($area->region, $region) === 0
    );
  }

  /**
   * Read known directed connections without discovering places or changing access.
   * The current location is known by being here, even before its visit is recorded.
   *
   * @return array{places: array<string, array{position: array{0:int,1:int}, name:?string, visited:bool}>, links:list<array{from:string,to:string}>}
   */
  public static function getKnownConnections(GameState $state, string $currentId): array
  {
    $areas = self::areas();
    $current = $areas[$currentId] ?? null;
    if ($current === null) {
      return ['places' => [$currentId => ['position' => [0, 0], 'name' => null, 'visited' => true]], 'links' => []];
    }
    $positions = self::place($currentId);
    $taken = array_fill_keys(array_map(self::key(...), $positions), true);
    $places = [];
    foreach (self::inRegion($current->region) as $id => $area) {
      if ($id === $currentId || $state->hasVisitedMap($id)) {
        $places[$id] = ['position' => $positions[$id], 'name' => $area->name, 'visited' => true];
      }
    }
    $links = [];
    $append = static function (RegionRoute $route, bool $includeDestination) use (&$places, &$positions, &$taken, &$links, $areas, $state): void {
      if (!$route->isVisible($state)) { return; }
      $from = $route->sourceId;
      $to = $route->destinationId;
      if (!isset($places[$to])) {
        if (!$includeDestination) { return; }
        if (!isset($positions[$to])) {
          $positions[$to] = self::freeCell($positions[$from], $route->direction, $taken);
          $taken[self::key($positions[$to])] = true;
        }
        $visited = $state->hasVisitedMap($to);
        $places[$to] = ['position' => $positions[$to], 'name' => $visited ? ($areas[$to]->name ?? null) : null, 'visited' => $visited];
      }
      if (!$places[$to]['visited'] && $places[$to]['name'] === null) { $places[$to]['name'] = $route->getKnownName($state); }
      $links[$from . "\0" . $to] = ['from' => $from, 'to' => $to];
    };
    // Only visited regional sources expose neighbours. An inbound unvisited
    // source never reveals its own existence, even if its destination is known.
    $sources = array_keys($places);
    foreach ($sources as $id) {
      foreach ($areas[$id]->routes as $route) { $append($route, true); }
    }
    // A visited external neighbour may contribute its genuine return edge,
    // but cannot expand this region into its own unexplored neighbours.
    foreach (array_diff(array_keys($places), $sources) as $id) {
      if (!$places[$id]['visited']) { continue; }
      foreach ($areas[$id]->routes ?? [] as $route) { $append($route, false); }
    }
    return ['places' => $places, 'links' => array_values($links)];
  }

  /**
   * Places a region's areas on a grid, laid out the way the world is.
   *
   * A project can pin a place with a `station` in its map data, and anything
   * unpinned is placed from the direction its door leads: the house whose door
   * is on the north-west side of the square is drawn north-west of it. Places
   * whose direction is unknown are set down beside what they connect to, so
   * they are still on the map.
   *
   * @param string $currentId The map the player is on.
   * @return array<string, array{0: int, 1: int}> The grid position of each area.
   */
  public static function place(string $currentId): array
  {
    $areas = self::areas();
    $current = $areas[$currentId] ?? null;

    if ($current === null) {
      return [];
    }

    $region = self::inRegion($current->region);

    // Laid out from the region's hub rather than from the player: a place does
    // not move because someone walked into it, and a map that rearranged
    // itself each time it was opened would be unreadable.
    $root = self::hubOf($region) ?? $currentId;
    $placed = [];
    $taken = [];
    $duplicates = [];
    // Reserve every distinct authored pin before displacing duplicates or inferring doors.
    foreach ($region as $id => $area) {
      if ($area->station === null) { continue; }
      if (isset($taken[self::key($area->station)])) {
        $duplicates[$id] = $area->station;
        continue;
      }
      $placed[$id] = $area->station;
      $taken[self::key($area->station)] = true;
    }
    foreach ($duplicates as $id => $station) {
      $position = self::freeCell($station, CompassDirection::EAST, $taken);
      $placed[$id] = $position;
      $taken[self::key($position)] = true;
    }
    if (!isset($placed[$root])) {
      $placed[$root] = isset($taken['0:0']) ? self::freeCell([0, 0], null, $taken) : [0, 0];
      $taken[self::key($placed[$root])] = true;
    }
    $queue = [$root];
    $queued = [$root => true];

    while ($queue !== []) {
      $id = array_shift($queue);

      foreach (($areas[$id]->links ?? []) as $link => $direction) {
        if (isset($queued[$link]) || ! isset($region[$link])) {
          continue;
        }

        if (!isset($placed[$link])) {
          $position = self::freeCell($placed[$id], $direction, $taken);
          $placed[$link] = $position;
          $taken[self::key($position)] = true;
        }
        $queued[$link] = true;
        $queue[] = $link;
      }
    }

    // A region can hold places no door reaches from here, a map only a
    // cutscene enters, say. They still belong on the map.
    foreach ($region as $id => $area) {
      if (isset($placed[$id])) {
        continue;
      }

      $position = $area->station ?? self::freeCell([0, 0], CompassDirection::SOUTH, $taken);
      $placed[$id] = $position;
      $taken[self::key($position)] = true;
    }

    return self::normalize($placed);
  }

  /**
   * Returns the place a region is drawn around.
   *
   * The busiest place, which in practice is the town square or the hall a
   * dungeon's rooms hang off, and the one whose doors describe the most of
   * the region's shape.
   *
   * @param array<string, RegionArea> $region The region's areas.
   * @return string|null The hub's map id, or null for an empty region.
   */
  protected static function hubOf(array $region): ?string
  {
    $hub = null;
    $mostDoors = -1;

    foreach ($region as $id => $area) {
      $doors = count(array_filter(
        $area->links,
        static fn(?CompassDirection $direction, string $link): bool => isset($region[$link]),
        ARRAY_FILTER_USE_BOTH
      ));

      // Ties go to the first by id, so the layout never depends on the order
      // the files happened to be read in.
      if ($doors > $mostDoors || ($doors === $mostDoors && $hub !== null && $id < $hub)) {
        $hub = $id;
        $mostDoors = $doors;
      }
    }

    return $hub;
  }

  /**
   * Finds a free cell in the given direction from a place.
   *
   * Two houses on the same side of a square would otherwise land on the same
   * cell, so a taken cell pushes the next one further out the same way.
   *
   * @param array{0: int, 1: int} $from The place to step from.
   * @param CompassDirection|null $direction The way to step, if known.
   * @param array<string, true> $taken The cells already used.
   * @return array{0: int, 1: int} The free cell.
   */
  protected static function freeCell(array $from, ?CompassDirection $direction, array $taken): array
  {
    [$stepX, $stepY] = ($direction ?? CompassDirection::EAST)->offset();

    for ($distance = 1; $distance <= count($taken) + 1; $distance++) {
      $candidate = [$from[0] + $stepX * $distance, $from[1] + $stepY * $distance];

      if (! isset($taken[self::key($candidate)])) {
        return $candidate;
      }
    }

    throw new \LogicException('A finite occupied grid must have a free cell.');
  }

  /**
   * Shifts every position so the grid starts at its top left corner.
   *
   * @param array<string, array{0: int, 1: int}> $placed The placed areas.
   * @return array<string, array{0: int, 1: int}> The normalized positions.
   */
  protected static function normalize(array $placed): array
  {
    if ($placed === []) {
      return [];
    }

    $offsetX = min(array_column($placed, 0));
    $offsetY = min(array_column($placed, 1));

    return array_map(
      static fn(array $position): array => [$position[0] - $offsetX, $position[1] - $offsetY],
      $placed
    );
  }

  /**
   * Returns the lookup key of a grid cell.
   *
   * @param array{0: int, 1: int} $position The cell.
   * @return string The key.
   */
  protected static function key(array $position): string
  {
    return "{$position[0]}:{$position[1]}";
  }

  /**
   * Returns the areas outside the region that its doors lead to.
   *
   * @param string $region The region name.
   * @return RegionArea[] The neighbouring areas, one per destination region.
   */
  public static function exits(string $region): array
  {
    $areas = self::areas();
    $exits = [];

    foreach (self::inRegion($region) as $area) {
      foreach (array_keys($area->links) as $link) {
        $destination = $areas[$link] ?? null;

        if ($destination === null || strcasecmp($destination->region, $region) === 0) {
          continue;
        }

        $exits[$destination->region] ??= $destination;
      }
    }

    return array_values($exits);
  }

  /**
   * Reads every map in the project.
   *
   * @return array<string, RegionArea> The areas, keyed by map id.
   */
  protected static function scan(?string $root = null): array
  {
    $root ??= Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Maps');

    if (! is_dir($root)) {
      return [];
    }

    $areas = [];

    foreach (self::dataFiles($root) as $filename) {
      $id = self::idOf($root, $filename);

      try {
        $leaf = basename($filename, '.data.php');
        $source = MapSourceReader::readEventSource(['id' => $id, 'data' => $filename,
          'event' => dirname($filename) . DIRECTORY_SEPARATOR . $leaf . '.event.php']);
        $data = $source['data'];
        $catalog = MapLandmarkCatalog::readMap($data, $source['eventLayer']);
        $routes = self::getRoutes($id, $data, $catalog, $source['eventLayer']);
      } catch (Throwable $exception) {
        Debug::warn(sprintf('Could not read map %s: %s', $id, $exception->getMessage()));
        continue;
      }

      $links = [];
      foreach ($routes as $route) {
        if (!array_key_exists($route->destinationId, $links)) { $links[$route->destinationId] = $route->direction; }
      }
      $areas[$id] = new RegionArea(
        $id,
        strval($data['name'] ?? $id),
        strval($data['region'] ?? ''),
        $links,
        self::stationOf($data),
        strval($data['description'] ?? ''),
        $routes,
      );
    }

    return $areas;
  }

  /**
   * Derives a map's id from where its data file sits.
   *
   * A map lives in its own directory with its files named after it
   * (`happyville/town-center/town-center.data.php`), and the id the engine and
   * every `destinationMap` use is the directory: `happyville/town-center`. A
   * data file that does not follow that shape keeps its own path as its id.
   *
   * @param string $root The maps directory.
   * @param string $filename The data file.
   * @return string The map id.
   */
  protected static function idOf(string $root, string $filename): string
  {
    $relative = str_replace(
      [$root . DIRECTORY_SEPARATOR, '.data.php', DIRECTORY_SEPARATOR],
      ['', '', '/'],
      $filename
    );

    $directory = dirname($relative);

    return $directory !== '.' && basename($relative) === basename($directory)
      ? $directory
      : $relative;
  }

  /** Catalog validation owns names, secrecy and defaults; only validated anchor ids are indexed here.
   *  @return list<RegionRoute> */
  private static function getRoutes(string $id, array $data, MapLandmarkCatalog $catalog, array $eventLayer): array
  {
    $declared = ['event' => [], 'trigger' => []];
    foreach ($data['landmarks'] ?? [] as $entry) {
      foreach ($entry['anchor'] as $type => $anchor) {
        if (isset($declared[$type])) { $declared[$type][$anchor] = $entry['id']; }
      }
    }
    $definitions = array_column($catalog->landmarks, null, 'id');
    $width = max(1, ...array_map('count', $eventLayer));
    $height = max(1, count($eventLayer));
    $routes = [];
    $append = static function (string $type, string $anchor, mixed $destination) use (&$routes, $declared, $definitions, $id, $width, $height): void {
      $automatic = ($type === 'event' ? '@event:' : '@legacy:') . $anchor;
      $definition = $definitions[$declared[$type][$anchor] ?? $automatic] ?? null;
      if ($definition === null || !$definition->kind->isRoute()) { return; }
      if (!is_string($destination) || trim($destination) === '') {
        throw new \InvalidArgumentException('A region transfer requires a nonempty destinationMap.');
      }
      $cells = $definition->area->cells;
      $direction = CompassDirection::fromPosition(
        array_sum(array_column($cells, 0)) / count($cells) / $width,
        array_sum(array_column($cells, 1)) / count($cells) / $height,
      );
      $routes[] = new RegionRoute($id, trim($destination), $definition, $direction);
    };
    foreach ($data['events'] ?? [] as $index => $event) {
      $append('event', strval($event['marker'] ?? '@' . $index), $event['data']['destinationMap'] ?? null);
    }
    foreach ($data['triggers'] ?? [] as $index => $trigger) {
      $append('trigger', strval($trigger['id'] ?? '@' . $index), $trigger['destinationMap'] ?? null);
    }
    return $routes;
  }

  /**
   * Reads where a project wants a place drawn, when it says.
   *
   * @param array<string, mixed> $data The map data.
   * @return array{0: int, 1: int}|null The grid position, or null.
   */
  protected static function stationOf(array $data): ?array
  {
    $station = $data['station'] ?? null;

    if (! is_array($station) || ! isset($station['x'], $station['y'])) {
      return null;
    }

    return [intval($station['x']), intval($station['y'])];
  }

  /**
   * Lists every map data file under the maps directory.
   *
   * @param string $root The maps directory.
   * @return string[] The absolute filenames.
   */
  protected static function dataFiles(string $root): array
  {
    $files = [];
    $directories = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

    foreach ($directories as $file) {
      if ($file->isFile() && str_ends_with($file->getFilename(), '.data.php')) {
        $files[] = $file->getPathname();
      }
    }

    sort($files);

    return $files;
  }
}
