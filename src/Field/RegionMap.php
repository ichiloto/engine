<?php

namespace Ichiloto\Engine\Field;

use Assegai\Util\Path;
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
   * Arranges a region into columns, by how far each area is from where the
   * player is standing.
   *
   * The player's area is the first column, everything one door away is the
   * second, and so on, which is what makes the layout answer "how do I get
   * from here to there?" rather than just listing places.
   *
   * @param string $currentId The map the player is on.
   * @return array<int, string[]> The map ids, by column.
   */
  public static function layout(string $currentId): array
  {
    $areas = self::areas();
    $current = $areas[$currentId] ?? null;

    if ($current === null) {
      return [];
    }

    $region = self::inRegion($current->region);
    $columns = [[$currentId]];
    $placed = [$currentId => true];

    while (true) {
      $next = [];

      foreach (end($columns) as $id) {
        foreach (($areas[$id]->links ?? []) as $link) {
          // Doors out of the region are shown as exits, not as places on it.
          if (isset($placed[$link]) || ! isset($region[$link])) {
            continue;
          }

          $placed[$link] = true;
          $next[] = $link;
        }
      }

      if ($next === []) {
        break;
      }

      $columns[] = $next;
    }

    // A region can hold places no door reaches from here (a map only entered
    // by a cutscene, say). They still belong on the map.
    $stranded = array_values(array_diff(array_keys($region), array_keys($placed)));

    if ($stranded !== []) {
      $columns[] = $stranded;
    }

    return $columns;
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
      foreach ($area->links as $link) {
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
        self::destinationsOf($data),
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
   * Returns the maps a map's transfer events lead to.
   *
   * @param array<string, mixed> $data The map data.
   * @return string[] The destination map ids.
   */
  protected static function destinationsOf(array $data): array
  {
    $destinations = [];

    foreach ((array) ($data['events'] ?? []) as $event) {
      if (! is_array($event)) {
        continue;
      }

      $destination = trim(strval($event['data']['destinationMap'] ?? ''));

      if ($destination !== '' && ! in_array($destination, $destinations, true)) {
        $destinations[] = $destination;
      }
    }

    return $destinations;
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
