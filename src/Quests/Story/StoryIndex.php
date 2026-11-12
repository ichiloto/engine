<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Quests\Story;

use FilesystemIterator;
use Ichiloto\Engine\Field\MapSourceReader;
use Ichiloto\Engine\Util\Debug;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * What leads to each step of a project's story: for every story flag, the
 * events, NPC pages and encounter maps that can set it; for every quest,
 * those that can start it; and the doors that wait for a flag.
 *
 * One reading of the project serves both the Editor, which checks that the
 * wayfinder always has an objective to lead with ({@see StoryGuidance}), and
 * the runtime, which leads the player itself when it has none
 * ({@see StoryWayfinder}).
 */
final class StoryIndex
{
  /** @var array<string, list<StorySource>> */
  private array $setters = [];
  /** @var array<string, list<StorySource>> */
  private array $starters = [];
  /** @var array<string, list<StorySource>> NPC pages by lower-case NPC name. */
  private array $speakers = [];
  /** @var array<string, list<StorySource>> */
  private array $battles = [];

  /**
   * @param list<StorySource> $sources What the player can set off, on every map.
   * @param list<StoryGate> $doors Doors that turn the player back (a `whenBlocked` transfer) until a flag is set.
   * @param array<string, list<string>> $troopEnemies The enemies each troop fields, by troop name.
   */
  public function __construct(public readonly array $sources = [], public readonly array $doors = [],
    public readonly array $troopEnemies = [])
  {
    foreach ($this->sources as $source) {
      foreach (array_keys($source->effects->flags) as $flag) {
        $this->setters[strval($flag)][] = $source;
      }
      foreach (array_keys($source->effects->quests) as $questId) {
        $this->starters[strval($questId)][] = $source;
      }
      foreach (array_keys($source->effects->troops) as $troop) {
        $this->battles[strval($troop)][] = $source;
      }
      if ($source->page !== null && $source->name !== '') {
        $this->speakers[mb_strtolower($source->name)][] = $source;
      }
    }
  }

  /**
   * Reads every map's content through one reader.
   *
   * @param array<string, array<string, mixed>> $maps Map data by map id.
   */
  public static function read(StoryContentReader $reader, array $maps): self
  {
    $sources = [];
    $doors = [];
    $troops = [];
    foreach ($maps as $mapId => $map) {
      $mapId = strval($mapId);
      foreach ($reader->readMapSources($mapId, $map) as $source) {
        $sources[] = $source;
        $troops += $source->effects->troops;
      }
      foreach (is_array($map['events'] ?? null) ? $map['events'] : [] as $key => $event) {
        if (!is_array($event) || !is_string($event['whenBlocked'] ?? null)
          || !str_ends_with(strval($event['class'] ?? ''), 'TransferPlayerTrigger')) {
          continue;
        }
        $marker = is_string($event['marker'] ?? null) ? $event['marker'] : strval($key);
        foreach (self::readRequiredFlags($event['conditions'] ?? []) as $flag) {
          $doors[] = new StoryGate($flag, map: $mapId, marker: $marker);
        }
      }
    }
    $troopEnemies = [];
    foreach (array_keys($troops) as $troop) {
      $troopEnemies[strval($troop)] = $reader->listTroopEnemies(strval($troop));
    }

    return new self($sources, $doors, $troopEnemies);
  }

  /** Reads every map under `<assetRoot>/Maps`. Unreadable maps are noted and lead nowhere. */
  public static function scan(string $assetRoot): self
  {
    $root = realpath($assetRoot . '/Maps');
    if ($root === false || !is_dir($root)) {
      return new self();
    }
    $maps = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      if (!$file->isFile() || !str_ends_with($file->getFilename(), '.data.php')) { continue; }
      $id = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPath(), strlen($root) + 1));
      if ($id === '') { continue; }
      try {
        $maps[$id] = MapSourceReader::readEventSource(MapSourceReader::resolvePaths($root, $id))['data'];
      } catch (Throwable $error) {
        Debug::warn(sprintf('Map %s is unavailable to story guidance: %s', $id, $error->getMessage()));
      }
    }
    ksort($maps);

    return self::read(StoryContentReader::fromAssetRoot($assetRoot), $maps);
  }

  /** @return list<StorySource> What can set a story flag. */
  public function findSetters(string $flag): array
  {
    return $this->setters[$flag] ?? [];
  }

  /** @return list<StorySource> What can start a quest. */
  public function findStarters(string $questId): array
  {
    return $this->starters[$questId] ?? [];
  }

  /** @return list<StorySource> The pages of NPCs with this name, which `talk_to` matches. */
  public function findSpeakers(string $name): array
  {
    return $this->speakers[mb_strtolower(trim($name))] ?? [];
  }

  /** @return list<StorySource> What can start a battle against this enemy. */
  public function findBattles(string $enemyName): array
  {
    $found = [];
    foreach ($this->troopEnemies as $troop => $enemies) {
      if (array_any($enemies, static fn(string $enemy): bool => strcasecmp($enemy, trim($enemyName)) === 0)) {
        foreach ($this->battles[$troop] ?? [] as $source) {
          $found[spl_object_id($source)] = $source;
        }
      }
    }

    return array_values($found);
  }

  /**
   * The story flags a condition list needs set: its positive story events
   * and switches.
   *
   * @return list<string>
   */
  public static function readRequiredFlags(mixed $conditions): array
  {
    $flags = [];
    foreach (is_array($conditions) ? $conditions : [] as $condition) {
      if (!is_array($condition) || (bool) ($condition['negate'] ?? false) || !is_string($condition['name'] ?? null)) {
        continue;
      }
      $type = $condition['type'] ?? null;
      if ($type === 'event' || ($type === 'switch' && ($condition['value'] ?? true) !== false)) {
        $flags[] = trim($condition['name']);
      }
    }

    return $flags;
  }
}
