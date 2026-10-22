<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Quests\Story;

use Closure;
use Ichiloto\Engine\Field\EncounterEntry;
use Ichiloto\Engine\Field\NpcPages;
use Ichiloto\Engine\Quests\QuestWaymarkSubject;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * Reads what authored content can do to the story, without running it.
 *
 * Content is walked whole: commands, `sets`, and the event scripts and
 * cinematics it runs (`scriptId`, `cinematicId`, `common_event`). Battles are
 * followed too: a `start_battle` command, or a map's random encounters, can
 * do whatever its troop's event pages do, since those pages run in that
 * troop's battles. Conditions are read, never run, so they are skipped, and
 * every branch counts.
 *
 * Effects the Engine records itself are included where their cause is
 * content: finishing a cinematic records `cinematic:<id>:completed`.
 */
final class StoryContentReader
{
  /** Event script and cinematic ids, as the runtime accepts them. */
  private const string SCRIPT_ID_PATTERN = '/^[a-zA-Z0-9._-]+$/';
  private const string CINEMATIC_ID_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

  /** @var array<string, StoryEffects> */
  private array $scriptEffects = [];
  /** @var array<string, StoryEffects> */
  private array $cinematicEffects = [];
  /** @var array<string, StoryEffects> */
  private array $troopEffects = [];
  /** @var array<string, array<string, mixed>> Troop data by name, as `start_battle` and encounters name troops. */
  private array $troops = [];

  /**
   * @param Closure(string): mixed $readScript Reads an event script's commands by id.
   * @param Closure(string): mixed $readCinematic Reads a cinematic's data and script by id.
   * @param iterable<mixed> $troops The project's troops, as `Data/troops.php` lists them.
   */
  public function __construct(private readonly Closure $readScript, private readonly Closure $readCinematic, iterable $troops = [])
  {
    foreach ($troops as $troop) {
      $name = is_array($troop) && is_string($troop['name'] ?? null) ? trim($troop['name']) : '';
      if ($name !== '' && !isset($this->troops[$name])) {
        $this->troops[$name] = $troop;
      }
    }
  }

  /**
   * Reads a project's content from its asset root. The Editor passes what
   * its open files and troops hold, so unsaved edits count.
   *
   * @param (Closure(string): (array{exists: bool, payload: mixed}|null))|null $readCurrent What a file holds as
   *   authored now, by absolute path; null when the file on disk is current.
   * @param iterable<mixed>|null $troops Troop data; null reads `Data/troops.php`.
   */
  public static function fromAssetRoot(string $assetRoot, ?Closure $readCurrent = null, ?iterable $troops = null): self
  {
    $readFile = static function (string $filename, string $what) use ($readCurrent): mixed {
      $current = $readCurrent !== null ? $readCurrent($filename) : null;
      return $current !== null ? ($current['exists'] ? $current['payload'] : null) : self::readFile($filename, $what);
    };
    $readScript = static fn(string $id): mixed => preg_match(self::SCRIPT_ID_PATTERN, $id) === 1
      ? $readFile("{$assetRoot}/Events/{$id}.php", "Event script {$id}") : null;
    $readCinematic = static fn(string $id): mixed => preg_match(self::CINEMATIC_ID_PATTERN, $id) === 1
      ? array_map(static fn(string $part): mixed => $readFile("{$assetRoot}/Cutscenes/Cinematics/{$id}/{$id}.{$part}.php",
        "Cinematic {$id}"), ['data', 'script'])
      : null;
    $troops ??= is_array($read = $readFile("{$assetRoot}/Data/troops.php", 'Troops')) ? $read : [];

    return new self($readScript, $readCinematic, $troops);
  }

  /**
   * What one map offers the player to set off: every event, every NPC page
   * and, when the map has random encounters, the map as a whole.
   *
   * @param array<string, mixed> $map The map's data, authored (events keyed by marker) or resolved (events listing their marker).
   * @return list<StorySource>
   */
  public function readMapSources(string $mapId, array $map): array
  {
    $sources = [];
    foreach (is_array($map['events'] ?? null) ? $map['events'] : [] as $key => $event) {
      if (!is_array($event)) { continue; }
      $marker = is_string($event['marker'] ?? null) ? $event['marker'] : strval($key);
      $sources[] = new StorySource($mapId, QuestWaymarkSubject::EVENT, $marker, $this->collectEffects($event));
    }
    foreach (is_array($map['npcs'] ?? null) ? $map['npcs'] : [] as $npc) {
      $id = is_array($npc) && is_string($npc['id'] ?? null) ? trim($npc['id']) : '';
      if ($id === '') { continue; }
      try {
        $pages = NpcPages::fromEntry($npc)->pages;
      } catch (Throwable) {
        // A malformed NPC is reported by the checks that read NPCs; it leads nowhere here.
        continue;
      }
      // Sets on the NPC itself follow every conversation, so they belong to each page.
      $shared = $this->collectEffects(['sets' => $npc['sets'] ?? []]);
      foreach ($pages as $page) {
        $effects = $this->collectEffects(['lines' => $page->lines, 'script' => $page->script, 'scriptId' => $page->scriptId,
          'cinematicId' => $page->cinematicId, 'sets' => $page->sets]);
        $sources[] = new StorySource($mapId, QuestWaymarkSubject::NPC, $id, $effects->withEffects($shared), $page->index,
          trim(strval($npc['name'] ?? '')));
      }
    }
    $encounters = new StoryEffects();
    foreach ((array) ($map['encounters']['troops'] ?? []) as $troop => $value) {
      if (is_string($troop) && EncounterEntry::getFromValue($value) !== null) {
        $encounters = $encounters->withEffects($this->collectBattleEffects($troop));
      }
    }
    if (!$encounters->isEmpty) {
      $sources[] = new StorySource($mapId, null, '', $encounters);
    }

    return $sources;
  }

  /**
   * The enemies a troop fields, by the names `defeat` objectives match.
   *
   * @return list<string>
   */
  public function listTroopEnemies(string $troopName): array
  {
    $enemies = [];
    foreach ((array) ($this->troops[$troopName]['enemies'] ?? []) as $member) {
      $enemy = is_array($member) && is_string($member['enemy'] ?? null) ? trim($member['enemy']) : '';
      if ($enemy !== '') { $enemies[] = $enemy; }
    }

    return array_values(array_unique($enemies));
  }

  /** What a piece of content can do, through everything it runs. */
  public function collectEffects(mixed $node): StoryEffects
  {
    if (!is_array($node)) {
      return new StoryEffects();
    }

    $type = $node['type'] ?? null;
    $name = is_string($node['name'] ?? null) ? trim($node['name']) : '';
    $id = is_string($node['id'] ?? null) ? trim($node['id']) : '';
    $troop = is_string($node['troop'] ?? null) ? trim($node['troop']) : '';
    $effects = match (true) {
      $type === 'record_event' && $name !== '' => new StoryEffects([$name => true]),
      $type === 'set_switch' && $name !== '' && ($node['value'] ?? true) !== false => new StoryEffects([$name => true]),
      $type === 'accept_quest' && $id !== '' => new StoryEffects(quests: [$id => true]),
      $type === 'common_event' && $id !== '' => $this->collectScriptEffects($id),
      $type === 'start_battle' && $troop !== '' => $this->collectBattleEffects($troop),
      default => new StoryEffects(),
    };

    foreach ($node as $key => $value) {
      if (is_string($key) && ($key === 'conditions' || str_ends_with($key, 'Conditions'))) {
        continue;
      }
      $effects = $effects->withEffects(match (true) {
        $key === 'sets' && is_array($value) => self::readSets($value),
        $key === 'scriptId' && is_string($value) => $this->collectScriptEffects(trim($value)),
        $key === 'cinematicId' && is_string($value) => $this->collectCinematicEffects(trim($value)),
        default => $this->collectEffects($value),
      });
    }

    return $effects;
  }

  /** A battle with a troop: the troop itself, and whatever its event pages can do. */
  private function collectBattleEffects(string $troopName): StoryEffects
  {
    if (!isset($this->troopEffects[$troopName])) {
      // Marked first, so content that battles the same troop again ends the walk.
      $this->troopEffects[$troopName] = new StoryEffects(troops: [$troopName => true]);
      $pages = $this->troops[$troopName]['events'] ?? [];
      $this->troopEffects[$troopName] = new StoryEffects(troops: [$troopName => true])->withEffects($this->collectEffects(
        array_map(static fn(mixed $page): mixed => is_array($page) ? ($page['commands'] ?? []) : [], is_array($pages) ? $pages : [])));
    }

    return $this->troopEffects[$troopName];
  }

  private function collectScriptEffects(string $id): StoryEffects
  {
    if (!isset($this->scriptEffects[$id])) {
      // Marked first, so a script that runs itself again ends the walk.
      $this->scriptEffects[$id] = new StoryEffects();
      $this->scriptEffects[$id] = $this->collectEffects(($this->readScript)($id));
    }

    return $this->scriptEffects[$id];
  }

  private function collectCinematicEffects(string $id): StoryEffects
  {
    if (!isset($this->cinematicEffects[$id])) {
      $completed = new StoryEffects([sprintf('cinematic:%s:completed', $id) => true]);
      $this->cinematicEffects[$id] = $completed;
      $this->cinematicEffects[$id] = $completed->withEffects($this->collectEffects(($this->readCinematic)($id)));
    }

    return $this->cinematicEffects[$id];
  }

  /** @param array<int, mixed> $sets Authored world-state writes ({@see \Ichiloto\Engine\Core\WorldStateWriter}). */
  private static function readSets(array $sets): StoryEffects
  {
    $flags = [];
    $quests = [];
    foreach ($sets as $set) {
      $name = is_array($set) && is_string($set['name'] ?? null) ? trim($set['name']) : '';
      if ($name === '') { continue; }
      match ($set['type'] ?? null) {
        'event' => $flags[$name] = true,
        'switch' => ($set['value'] ?? true) !== false ? $flags[$name] = true : null,
        'quest' => $quests[$name] = true,
        default => null,
      };
    }

    return new StoryEffects($flags, $quests);
  }

  private static function readFile(string $filename, string $what): mixed
  {
    if (!is_file($filename)) {
      return null;
    }
    try {
      return (static fn(string $file): mixed => require $file)($filename);
    } catch (Throwable $error) {
      Debug::warn(sprintf('%s is unreadable for story guidance: %s', $what, $error->getMessage()));
      return null;
    }
  }
}
