<?php

namespace Ichiloto\Engine\Field\Reachability;

use Assegai\Util\Path;
use FilesystemIterator;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Events\Triggers\EventTriggerFactory;
use Ichiloto\Engine\Events\Triggers\SleepEventTrigger;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapSourceReader;
use Ichiloto\Engine\Field\MapTrigger;
use Ichiloto\Engine\Field\NpcPlacement;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Checks every map of a project for layouts that block the player, from
 * every place the project brings the player onto each map.
 *
 * Entrances are collected from the project's own data, never from a fixed
 * list: transfer events and edge triggers into a map, scripted `transfer`
 * commands in common events, cinematics and map data, sleep spawn points,
 * and the system starting position. Moving authored content freely is fine;
 * only a change that leaves an entrance, event or talkable NPC unreachable
 * is reported.
 *
 * @package Ichiloto\Engine\Field\Reachability
 */
final class ProjectReachability
{
  /**
   * @param array<string, MapReachabilityReport> $reports Map reports, keyed by map ID.
   * @param ReachabilityProblem[] $problems Problems found reading maps or their arrivals.
   */
  private function __construct(
    public readonly array $reports,
    public readonly array $problems,
  )
  {
  }

  /**
   * Analyses every map under a project's asset root.
   *
   * @param string $assetRoot The project's `assets` directory.
   */
  public static function analyze(string $assetRoot): self
  {
    $mapsDirectory = Path::join($assetRoot, 'Maps');
    $problems = [];
    $maps = [];

    try {
      $dictionary = MapSourceReader::loadCollisionDictionary(Path::join($mapsDirectory, 'collisions.php'));
    } catch (Throwable $error) {
      $dictionary = null;
      $problems[] = new ReachabilityProblem('', ReachabilityProblemKind::UNREADABLE_MAP,
        'The collision dictionary could not be read: ' . $error->getMessage());
    }

    foreach (self::findMapIds($mapsDirectory) as $mapId) {
      if ($dictionary === null) {
        break;
      }

      try {
        $source = MapSourceReader::readFiles(MapSourceReader::resolvePaths($mapsDirectory, $mapId));
        $events = array_map(ReachabilityEvent::fromDefinition(...), $source['data']['events']);
        // Only arrivals are built as triggers: they read nothing but their own data.
        $arrivals = array_map(
          static fn(array $event): EventTrigger => EventTriggerFactory::create($event, $mapId),
          array_values(array_filter($source['data']['events'], static fn(array $event): bool =>
            is_a(strval($event['class'] ?? ''), TransferPlayerTrigger::class, true)
            || is_a(strval($event['class'] ?? ''), SleepEventTrigger::class, true))),
        );
        $edgeTriggers = array_map(
          MapTrigger::tryFromArray(...),
          array_values(array_filter((array) ($source['data']['triggers'] ?? []), is_array(...))),
        );
        $maps[$mapId] = [
          'data' => $source['data'],
          'map' => new MapReachability(
            $mapId,
            MapCollisionResolver::resolveLayers($source['layers'], $dictionary),
            $events,
            $edgeTriggers,
            array_values(array_filter(array_map(NpcPlacement::fromArray(...), (array) ($source['data']['npcs'] ?? [])))),
          ),
          'arrivals' => $arrivals,
          'edgeTriggers' => $edgeTriggers,
        ];
      } catch (Throwable $error) {
        $problems[] = new ReachabilityProblem($mapId, ReachabilityProblemKind::UNREADABLE_MAP,
          'The map could not be read as the game reads it: ' . $error->getMessage());
      }
    }

    $entrances = array_fill_keys(array_keys($maps), []);
    $addArrival = static function (string $from, string $to, int $x, int $y, string $source)
      use (&$entrances, &$problems, $maps): void {
      if (! isset($maps[$to])) {
        $problems[] = new ReachabilityProblem($from, ReachabilityProblemKind::UNKNOWN_DESTINATION,
          sprintf('%s sends the player to map "%s", which the project does not have or cannot read.', $source, $to));

        return;
      }

      $entrances[$to][] = new ReachabilityEntrance($x, $y, $source);
    };

    foreach ($maps as $mapId => $map) {
      foreach ($map['arrivals'] as $event) {
        $marker = $event->marker ?? '?';

        if ($event instanceof TransferPlayerTrigger) {
          $addArrival($mapId, $event->destinationMap, intval($event->spawnPoint->x), intval($event->spawnPoint->y),
            "transfer {$marker} on {$mapId}");
        } elseif ($event instanceof SleepEventTrigger) {
          $addArrival($mapId, $mapId, intval($event->spawnPoint->x), intval($event->spawnPoint->y),
            "sleep event {$marker} on {$mapId}");
        }
      }

      foreach ($map['edgeTriggers'] as $trigger) {
        $addArrival($mapId, $trigger->destinationMap, intval($trigger->spawnPoint->x), intval($trigger->spawnPoint->y),
          "an edge trigger on {$mapId}");
      }

      foreach (self::findScriptedTransfers($map['data']) as $transfer) {
        $addArrival($mapId, $transfer['map'], $transfer['x'], $transfer['y'], "a scripted transfer on {$mapId}");
      }
    }

    foreach (self::findAuthoredScriptFiles($assetRoot) as $relative => $file) {
      $commands = self::requireIsolated($file);

      foreach (self::findScriptedTransfers($commands) as $transfer) {
        $addArrival($relative, $transfer['map'], $transfer['x'], $transfer['y'], "a scripted transfer in {$relative}");
      }
    }

    $start = self::readStartingPosition($assetRoot);

    if ($start !== null) {
      $addArrival('Data/system.php', $start['map'], $start['x'], $start['y'], 'the starting position');
    }

    $reports = [];

    foreach ($maps as $mapId => $map) {
      $reports[$mapId] = $map['map']->analyze($entrances[$mapId]);
    }

    return new self($reports, $problems);
  }

  /**
   * Returns every problem: reading problems first, then each map's.
   *
   * @return ReachabilityProblem[]
   */
  public function getAllProblems(): array
  {
    $problems = $this->problems;

    foreach ($this->reports as $report) {
      array_push($problems, ...$report->problems);
    }

    return $problems;
  }

  /** @return string[] Map IDs: each directory holding a data file named after it. */
  private static function findMapIds(string $mapsDirectory): array
  {
    if (! is_dir($mapsDirectory)) {
      return [];
    }

    $ids = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mapsDirectory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
      $directory = $file->getPath();

      if ($file->getFilename() === basename($directory) . '.data.php') {
        $ids[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($directory, strlen(rtrim($mapsDirectory, DIRECTORY_SEPARATOR)) + 1));
      }
    }

    sort($ids);

    return $ids;
  }

  /** @return array<string, string> Common event and cinematic files, keyed by asset-relative path. */
  private static function findAuthoredScriptFiles(string $assetRoot): array
  {
    $files = [];

    foreach (['Events', 'Cutscenes'] as $directory) {
      $root = Path::join($assetRoot, $directory);

      if (! is_dir($root)) {
        continue;
      }

      foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
          $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(rtrim($assetRoot, DIRECTORY_SEPARATOR)) + 1));
          $files[$relative] = $file->getPathname();
        }
      }
    }

    ksort($files);

    return $files;
  }

  /**
   * Finds every `transfer` command in an authored command structure,
   * however deeply it is nested in branches, choices and dialogue.
   *
   * @return list<array{map: string, x: int, y: int}>
   */
  private static function findScriptedTransfers(mixed $node): array
  {
    if (! is_array($node)) {
      return [];
    }

    $transfers = [];

    if (($node['type'] ?? null) === 'transfer' && is_string($node['map'] ?? null)
      && is_int($node['x'] ?? null) && is_int($node['y'] ?? null)) {
      $transfers[] = ['map' => trim($node['map']), 'x' => $node['x'], 'y' => $node['y']];
    }

    foreach ($node as $child) {
      array_push($transfers, ...self::findScriptedTransfers($child));
    }

    return $transfers;
  }

  /** @return array{map: string, x: int, y: int}|null */
  private static function readStartingPosition(string $assetRoot): ?array
  {
    $system = self::requireIsolated(Path::join($assetRoot, 'Data', 'system.php'));
    $player = is_array($system) ? ($system['startingPositions']['player'] ?? null) : null;

    if (! is_array($player) || ! is_string($player['destinationMap'] ?? null)
      || ! is_numeric($player['spawnPoint']['x'] ?? null) || ! is_numeric($player['spawnPoint']['y'] ?? null)) {
      return null;
    }

    return ['map' => $player['destinationMap'], 'x' => intval($player['spawnPoint']['x']), 'y' => intval($player['spawnPoint']['y'])];
  }

  /** Requires an authored PHP file in isolation; an unreadable one yields null (its own validation reports it). */
  private static function requireIsolated(string $file): mixed
  {
    if (! is_file($file)) {
      return null;
    }

    try {
      return (static fn(string $isolated): mixed => require $isolated)($file);
    } catch (Throwable) {
      return null;
    }
  }
}
