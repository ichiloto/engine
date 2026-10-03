<?php

namespace Ichiloto\Engine\Field\Reachability;

use Assegai\Util\Path;
use Closure;
use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\Rect;
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

    // Every arrival, with what the player must reach for it to happen: null
    // for one that can happen anywhere (the start, common events, cinematics).
    $arrivals = [];
    $addArrival = static function (string $from, string $to, int $x, int $y, string $source, ?Closure $requires)
      use (&$arrivals, &$problems, $maps): void {
      if (! isset($maps[$to])) {
        $problems[] = new ReachabilityProblem($from, ReachabilityProblemKind::UNKNOWN_DESTINATION,
          sprintf('%s sends the player to map "%s", which the project does not have or cannot read.', $source, $to));

        return;
      }

      $arrivals[] = ['from' => $requires === null ? null : $from, 'to' => $to,
        'entrance' => new ReachabilityEntrance($x, $y, $source), 'requires' => $requires];
    };
    $standingIn = static fn(Rect|CellArea $area): Closure => static fn(MapReachabilityReport $report): bool => $report->isAnyReachable($area);
    $besideNpc = static fn(NpcPlacement $npc): Closure => static fn(MapReachabilityReport $report): bool =>
      $report->canSpeakTo($npc->x, $npc->y);

    foreach ($maps as $mapId => $map) {
      foreach ($map['arrivals'] as $event) {
        $marker = $event->marker ?? '?';

        if ($event instanceof TransferPlayerTrigger) {
          $addArrival($mapId, $event->destinationMap, intval($event->spawnPoint->x), intval($event->spawnPoint->y),
            "transfer {$marker} on {$mapId}", $standingIn($event->area));
        } elseif ($event instanceof SleepEventTrigger) {
          $addArrival($mapId, $mapId, intval($event->spawnPoint->x), intval($event->spawnPoint->y),
            "sleep event {$marker} on {$mapId}", $standingIn($event->area));
        }
      }

      foreach ($map['edgeTriggers'] as $trigger) {
        $addArrival($mapId, $trigger->destinationMap, intval($trigger->spawnPoint->x), intval($trigger->spawnPoint->y),
          "an edge trigger on {$mapId}", $standingIn($trigger->area));
      }

      // A scripted transfer in an NPC's lines happens when it is spoken to;
      // one in an event's data, when the event fires; anywhere else in the
      // map's data, once the player is on the map.
      foreach ((array) ($map['data']['npcs'] ?? []) as $entry) {
        $npc = NpcPlacement::fromArray($entry);

        foreach ($npc === null ? [] : self::findScriptedArrivals($entry, $mapId) as $arrival) {
          $addArrival($mapId, $arrival['map'], $arrival['x'], $arrival['y'],
            "a scripted {$arrival['kind']} by NPC {$npc->name} on {$mapId}", $besideNpc($npc));
        }
      }

      foreach ($map['data']['events'] as $definition) {
        $event = ReachabilityEvent::fromDefinition($definition);

        foreach (self::findScriptedArrivals($definition['data'] ?? [], $mapId) as $arrival) {
          $addArrival($mapId, $arrival['map'], $arrival['x'], $arrival['y'],
            sprintf('a scripted %s in event %s on %s', $arrival['kind'], $event->marker ?? '?', $mapId), $standingIn($event->area));
        }
      }

      $mapLevel = array_diff_key($map['data'], ['npcs' => true, 'events' => true]);

      foreach (self::findScriptedArrivals($mapLevel, $mapId) as $arrival) {
        $addArrival($mapId, $arrival['map'], $arrival['x'], $arrival['y'], "a scripted {$arrival['kind']} on {$mapId}",
          static fn(): bool => true);
      }
    }

    foreach (self::findAuthoredScriptFiles($assetRoot) as $relative => $file) {
      $commands = self::requireIsolated($file);

      // A shared script runs on whichever map calls it, so only its
      // transfers name the map they arrive on.
      foreach (self::findScriptedArrivals($commands, null) as $arrival) {
        $addArrival($relative, $arrival['map'], $arrival['x'], $arrival['y'], "a scripted {$arrival['kind']} in {$relative}", null);
      }
    }

    $start = self::readStartingPosition($assetRoot);

    if ($start !== null) {
      $addArrival('Data/system.php', $start['map'], $start['x'], $start['y'], 'the starting position', null);
    }

    // Spread from the arrivals that can happen anywhere until nothing new is
    // reached: an arrival counts only once the player can reach its source.
    $entrances = array_fill_keys(array_keys($maps), []);
    $reports = [];

    do {
      $changed = false;

      foreach ($arrivals as $index => $arrival) {
        $from = $arrival['from'];

        if ($from !== null && ! isset($reports[$from])) {
          continue;
        }

        if ($from !== null && ! ($arrival['requires'])($reports[$from])) {
          continue;
        }

        $entrances[$arrival['to']][] = $arrival['entrance'];
        unset($arrivals[$index]);
        $reports[$arrival['to']] = $maps[$arrival['to']]['map']->analyze($entrances[$arrival['to']]);
        $changed = true;
      }
    } while ($changed);

    foreach ($maps as $mapId => $map) {
      $reports[$mapId] ??= $map['map']->analyze([]);
    }

    ksort($reports);
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
   * Finds every place an authored command structure puts the player,
   * however deeply it is nested in branches, choices and dialogue: each
   * `transfer`, and each `inn` stay that wakes the party elsewhere on the
   * map running the script.
   *
   * @param string|null $currentMap The map the script runs on, when it is known.
   * @return list<array{kind: string, map: string, x: int, y: int}>
   */
  private static function findScriptedArrivals(mixed $node, ?string $currentMap): array
  {
    if (! is_array($node)) {
      return [];
    }

    $arrivals = [];
    $type = $node['type'] ?? null;

    if ($type === 'transfer' && is_string($node['map'] ?? null)
      && is_int($node['x'] ?? null) && is_int($node['y'] ?? null)) {
      $arrivals[] = ['kind' => 'transfer', 'map' => trim($node['map']), 'x' => $node['x'], 'y' => $node['y']];
    }

    if ($type === 'inn' && $currentMap !== null
      && is_int($node['spawnPoint']['x'] ?? null) && is_int($node['spawnPoint']['y'] ?? null)) {
      $arrivals[] = ['kind' => 'inn stay', 'map' => $currentMap, 'x' => $node['spawnPoint']['x'], 'y' => $node['spawnPoint']['y']];
    }

    foreach ($node as $child) {
      array_push($arrivals, ...self::findScriptedArrivals($child, $currentMap));
    }

    return $arrivals;
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
