<?php

namespace Ichiloto\Engine\Field;

use Assegai\Util\Path;
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
    $placed = [$root => $region[$root]->station ?? [0, 0]];
    $taken = [self::key($placed[$root]) => true];
    $queue = [$root];

    while ($queue !== []) {
      $id = array_shift($queue);

      foreach (($areas[$id]->links ?? []) as $link => $direction) {
        if (isset($placed[$link]) || ! isset($region[$link])) {
          continue;
        }

        $position = $region[$link]->station
          ?? self::freeCell($placed[$id], $direction, $taken);

        $placed[$link] = $position;
        $taken[self::key($position)] = true;
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

    for ($distance = 1; $distance < 32; $distance++) {
      $candidate = [$from[0] + $stepX * $distance, $from[1] + $stepY * $distance];

      if (! isset($taken[self::key($candidate)])) {
        return $candidate;
      }
    }

    return [$from[0] + $stepX, $from[1] + $stepY];
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
        $data = require $filename;
      } catch (Throwable $exception) {
        Debug::warn(sprintf('Could not read map %s: %s', $id, $exception->getMessage()));
        continue;
      }

      if (! is_array($data)) {
        continue;
      }

      $areas[$id] = new RegionArea(
        $id,
        strval($data['name'] ?? $id),
        strval($data['region'] ?? ''),
        self::doorsOf($data, dirname($filename)),
        self::stationOf($data),
        strval($data['description'] ?? ''),
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

  /**
   * Returns the maps a map's doors lead to, and which way each lies.
   *
   * Where a door sits on its map is where the place behind it lies: the shop's
   * door is on the south side of the square because the shop is south of it.
   * That is the only source of a region's shape that costs an author nothing,
   * so it is what the map is drawn from unless the project says otherwise.
   *
   * @param array<string, mixed> $data The map data.
   * @param string $directory The map's directory, holding its event layer.
   * @return array<string, CompassDirection|null> The destinations, and their directions.
   */
  protected static function doorsOf(array $data, string $directory): array
  {
    $markers = self::markerPositions($directory);
    $doors = [];

    foreach ((array) ($data['events'] ?? []) as $marker => $event) {
      if (! is_array($event)) {
        continue;
      }

      $destination = trim(strval($event['data']['destinationMap'] ?? ''));

      if ($destination === '' || isset($doors[$destination])) {
        continue;
      }

      $position = $markers[strval($marker)] ?? null;

      $doors[$destination] = $position === null
        ? null
        : CompassDirection::fromPosition($position[0], $position[1]);
    }

    return $doors;
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
   * Returns where each event marker sits on its map, as a fraction of the
   * map's width and height.
   *
   * @param string $directory The map's directory.
   * @return array<string, array{0: float, 1: float}> The marker positions.
   */
  protected static function markerPositions(string $directory): array
  {
    $filename = $directory . DIRECTORY_SEPARATOR . basename($directory) . '.event.php';

    if (! is_file($filename)) {
      return [];
    }

    try {
      $layer = require $filename;
    } catch (Throwable $exception) {
      Debug::warn(sprintf('Could not read the event layer %s: %s', $filename, $exception->getMessage()));

      return [];
    }

    $rows = is_array($layer) ? $layer : explode("\n", strval($layer));
    $height = max(1, count($rows));
    $width = 1;
    $cells = [];

    foreach ($rows as $y => $row) {
      $characters = is_array($row) ? $row : mb_str_split(strval($row));
      $width = max($width, count($characters));

      foreach ($characters as $x => $character) {
        if (trim($character) !== '') {
          $cells[$character][] = [$x, $y];
        }
      }
    }

    $positions = [];

    foreach ($cells as $marker => $coordinates) {
      // A marker covers several tiles, so its middle is what is placed.
      $positions[strval($marker)] = [
        (array_sum(array_column($coordinates, 0)) / count($coordinates)) / $width,
        (array_sum(array_column($coordinates, 1)) / count($coordinates)) / $height,
      ];
    }

    return $positions;
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
