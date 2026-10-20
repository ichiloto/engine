<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Quests\QuestLog;

/**
 * When each map's events and NPCs are there to be used, as their own source
 * authors it: an event's conditions and, where it shows a cue, its cue's
 * conditions; an NPC's presence conditions and, where any of its pages shows
 * a cue, the cue of the page talking would run ({@see NpcPages}). A one-shot
 * event or page stops once the world records it complete. Quest waymarks
 * follow these, so a waymark on an event or an NPC appears with its own "!"
 * and never needs its phase restated.
 */
final readonly class MapSubjectGates
{
  /**
   * @param array<string, array<string, list<list<array<string, mixed>>>>> $events Condition lists by map and marker.
   * @param array<string, array<string, array{presence: list<array<string, mixed>>, pages: NpcPages}>> $npcs Presence
   *   conditions and interaction pages by map and NPC id.
   */
  public function __construct(public array $events = [], public array $npcs = [])
  {
  }

  /**
   * Reads one map's gates from its resolved source.
   *
   * @param array<string, mixed> $map Resolved map data (MapSourceReader).
   * @return array{events: array<string, list<list<array<string, mixed>>>>, npcs: array<string, array{presence: list<array<string, mixed>>, pages: NpcPages}>}
   */
  public static function readMap(array $map): array
  {
    $events = [];
    foreach ($map['events'] ?? [] as $event) {
      $marker = $event['marker'] ?? null;
      if (!is_string($marker) || $marker === '') { continue; }
      $lists = [self::readConditions($event['conditions'] ?? [])];
      $cue = $event['cue'] ?? null;
      if (is_array($cue) && trim(strval($cue['symbol'] ?? '')) !== '') { $lists[] = self::readConditions($cue['conditions'] ?? []); }
      $events[$marker] = $lists;
    }
    $npcs = [];
    foreach (is_array($map['npcs'] ?? null) ? $map['npcs'] : [] as $entry) {
      $id = is_array($entry) ? trim(strval($entry['id'] ?? '')) : '';
      if ($id === '') { continue; }
      $npcs[$id] = ['presence' => self::readConditions($entry['conditions'] ?? []), 'pages' => NpcPages::fromEntry($entry)];
    }
    return ['events' => $events, 'npcs' => $npcs];
  }

  /** Whether a map's event can be used now; an event the map does not have cannot. */
  public function allowsEvent(string $map, string $marker, GameState $state, ?Party $party = null, ?QuestLog $questLog = null): bool
  {
    $lists = $this->events[$map][$marker] ?? null;
    if ($lists === null || $state->isEventComplete($map, $marker)) { return false; }
    return array_all($lists, static fn(array $conditions): bool => WorldConditionEvaluator::allHold($conditions, $state, $party, $questLog));
  }

  /**
   * Whether a map's NPC is present now and, where its pages show cues, the
   * page talking would run shows its cue; an NPC the map does not have is not.
   */
  public function allowsNpc(string $map, string $id, GameState $state, ?Party $party = null, ?QuestLog $questLog = null): bool
  {
    $npc = $this->npcs[$map][$id] ?? null;
    if ($npc === null || !WorldConditionEvaluator::allHold($npc['presence'], $state, $party, $questLog)) { return false; }
    if (!$npc['pages']->hasCue) { return true; }
    $cue = $npc['pages']->selectPage($map, $state, $party, $questLog)?->cue;
    return $cue !== null && WorldConditionEvaluator::allHold($cue->conditions, $state, $party, $questLog);
  }

  /** Whether a map's NPC is present now and talking to it runs the given page. */
  public function allowsNpcPage(string $map, string $id, int $page, GameState $state, ?Party $party = null, ?QuestLog $questLog = null): bool
  {
    $npc = $this->npcs[$map][$id] ?? null;
    return $npc !== null && WorldConditionEvaluator::allHold($npc['presence'], $state, $party, $questLog)
      && $npc['pages']->selectPage($map, $state, $party, $questLog)?->index === $page;
  }

  /** @return list<array<string, mixed>> */
  private static function readConditions(mixed $conditions): array
  {
    return is_array($conditions) ? array_values(array_filter($conditions, 'is_array')) : [];
  }
}
