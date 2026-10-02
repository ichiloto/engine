<?php

namespace Ichiloto\Engine\Field\Reachability;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\InteractionReach;
use Ichiloto\Engine\Field\MapTrigger;
use Ichiloto\Engine\Field\NpcPlacement;

/**
 * Where the player can walk on one map, under the field's own movement
 * rules, and what that leaves out of reach.
 *
 * The player moves one cell at a time in the four headings onto any cell
 * whose collision is not solid and that no NPC stands on
 * (MapManager::canMoveTo). Fixed NPCs block their cell; wanderers move, so
 * they never permanently block one. Stepping onto a transfer or edge trigger
 * takes the player off the map, so those cells are reached but not walked
 * through. An event fires while the player stands in its area, so it needs
 * one reachable cell; a talkable NPC is spoken to from a reachable cell that
 * reaches it, beside it or across counters (InteractionReach, as the field
 * talks).
 *
 * Story conditions are not evaluated. An NPC that only appears under
 * conditions is a story gate, like a conditional event: gates are assumed
 * open, so only an NPC present in every story state blocks its cell. A
 * conditional NPC is still spoken to while present, so it still needs a
 * reachable cell beside it.
 *
 * @package Ichiloto\Engine\Field\Reachability
 */
final readonly class MapReachability
{
  /**
   * @param int[][] $collisions The map's collision grid, CollisionType values.
   * @param ReachabilityEvent[] $events The map's events, with resolved areas.
   * @param MapTrigger[] $edgeTriggers The map's edge triggers.
   * @param NpcPlacement[] $npcs The map's NPCs.
   */
  public function __construct(
    public string $mapId,
    private array $collisions,
    private array $events = [],
    private array $edgeTriggers = [],
    private array $npcs = [],
  )
  {
  }

  /**
   * Floods the map from its entrances and reports what the player cannot
   * reach.
   *
   * @param ReachabilityEntrance[] $entrances Where the player arrives.
   */
  public function analyze(array $entrances): MapReachabilityReport
  {
    $blocked = [];

    foreach ($this->npcs as $npc) {
      if (! $npc->wanders && ! $npc->isConditional) {
        $blocked[self::key($npc->x, $npc->y)] = true;
      }
    }

    $exits = [];

    foreach ($this->getExitAreas() as $area) {
      foreach (self::cellsOf($area) as [$x, $y]) {
        $exits[self::key($x, $y)] = true;
      }
    }

    $problems = [];
    $reachable = [];
    $queue = [];

    if ($entrances === []) {
      $problems[] = new ReachabilityProblem($this->mapId, ReachabilityProblemKind::NO_ENTRANCE,
        'Nothing the player can reach from the start brings them onto it yet.');
    }

    foreach ($entrances as $entrance) {
      if (! $this->canStandOn($entrance->x, $entrance->y, $blocked)) {
        $problems[] = new ReachabilityProblem($this->mapId, ReachabilityProblemKind::BLOCKED_ENTRANCE,
          sprintf('The player arrives at (%d, %d) from %s, %s.', $entrance->x, $entrance->y, $entrance->source,
            $this->describeBlockedCell($entrance->x, $entrance->y, $blocked)),
          $entrance->x, $entrance->y);

        // Movement checks only the destination, so a player placed on a
        // blocked cell can still step off it; one outside the map cannot.
        if (! isset($this->collisions[$entrance->y][$entrance->x])) {
          continue;
        }
      }

      $key = self::key($entrance->x, $entrance->y);

      if (! isset($reachable[$key])) {
        $reachable[$key] = true;
        $queue[] = [$entrance->x, $entrance->y];
      }
    }

    while ($queue !== []) {
      [$x, $y] = array_shift($queue);

      // Arriving on an exit is allowed; stepping onto one leaves the map.
      if (isset($exits[self::key($x, $y)]) && ! $this->isEntranceCell($x, $y, $entrances)) {
        continue;
      }

      foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$dx, $dy]) {
        $nx = $x + $dx;
        $ny = $y + $dy;
        $key = self::key($nx, $ny);

        if (! isset($reachable[$key]) && $this->canStandOn($nx, $ny, $blocked)) {
          $reachable[$key] = true;
          $queue[] = [$nx, $ny];
        }
      }
    }

    if ($entrances !== []) {
      array_push($problems, ...$this->findUnreachableTargets($reachable));
    }

    $spokenTo = [];

    foreach ($this->npcs as $npc) {
      if ($this->isSpokenToFrom($npc, $reachable)) {
        $spokenTo[self::key($npc->x, $npc->y)] = true;
      }
    }

    return new MapReachabilityReport($this->mapId, $reachable, $problems, $spokenTo);
  }

  /** @return Rect[] Areas that take the player off the map. */
  private function getExitAreas(): array
  {
    $areas = array_map(static fn(MapTrigger $trigger): Rect => $trigger->area, $this->edgeTriggers);

    foreach ($this->events as $event) {
      if ($event->isExit) {
        $areas[] = $event->area;
      }
    }

    return $areas;
  }

  /**
   * @param array<string, true> $reachable
   * @return ReachabilityProblem[]
   */
  private function findUnreachableTargets(array $reachable): array
  {
    $problems = [];
    $isAnyReachable = static function (Rect $area) use ($reachable): bool {
      foreach (self::cellsOf($area) as [$x, $y]) {
        if (isset($reachable[self::key($x, $y)])) {
          return true;
        }
      }

      return false;
    };

    foreach ($this->events as $event) {
      if (! $isAnyReachable($event->area)) {
        $problems[] = new ReachabilityProblem($this->mapId, ReachabilityProblemKind::UNREACHABLE_EVENT,
          sprintf('No cell of event %s (%s) at (%d, %d) can be reached, so it never fires.',
            $event->marker ?? '?', basename(str_replace('\\', '/', $event->class)), $event->area->getX(), $event->area->getY()),
          $event->area->getX(), $event->area->getY());
      }
    }

    foreach ($this->edgeTriggers as $trigger) {
      if (! $isAnyReachable($trigger->area)) {
        $problems[] = new ReachabilityProblem($this->mapId, ReachabilityProblemKind::UNREACHABLE_EVENT,
          sprintf('No cell of the edge trigger to %s at (%d, %d) can be reached.',
            $trigger->destinationMap, $trigger->area->getX(), $trigger->area->getY()),
          $trigger->area->getX(), $trigger->area->getY());
      }
    }

    foreach ($this->npcs as $npc) {
      if ($npc->wanders || ! $npc->isTalkable) {
        continue;
      }

      if (! $this->isSpokenToFrom($npc, $reachable)) {
        $problems[] = new ReachabilityProblem($this->mapId, ReachabilityProblemKind::UNREACHABLE_NPC,
          sprintf('NPC %s at (%d, %d) can never be spoken to: no reachable cell is beside it or across a counter from it.',
            $npc->name, $npc->x, $npc->y),
          $npc->x, $npc->y);
      }
    }

    return $problems;
  }

  /**
   * Whether a reachable cell reaches the NPC under the field's own rule: walk
   * out from the NPC across counters to the first other cell, and ask
   * InteractionReach from there, facing back, which NPC it finds.
   *
   * @param array<string, true> $reachable
   */
  private function isSpokenToFrom(NpcPlacement $npc, array $reachable): bool
  {
    $occupied = [];

    foreach ($this->npcs as $other) {
      $occupied[self::key($other->x, $other->y)] = true;
    }

    $isCounterAt = fn(int $x, int $y): bool => ($this->collisions[$y][$x] ?? null) === CollisionType::COUNTER->value;
    $hasNpcAt = static fn(int $x, int $y): bool => isset($occupied[self::key($x, $y)]);

    foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$dx, $dy]) {
      [$x, $y] = [$npc->x + $dx, $npc->y + $dy];

      while ($isCounterAt($x, $y) && ! $hasNpcAt($x, $y)) {
        [$x, $y] = [$x + $dx, $y + $dy];
      }

      if (isset($reachable[self::key($x, $y)])
        && InteractionReach::findTalkCell($x, $y, -$dx, -$dy, $isCounterAt, $hasNpcAt) === [$npc->x, $npc->y]) {
        return true;
      }
    }

    return false;
  }

  /** @param array<string, true> $blocked */
  private function canStandOn(int $x, int $y, array $blocked): bool
  {
    $collision = $this->collisions[$y][$x] ?? null;

    return $collision !== null
      && ! in_array($collision, [CollisionType::SOLID->value, CollisionType::NPC->value, CollisionType::COUNTER->value], true)
      && ! isset($blocked[self::key($x, $y)]);
  }

  /** @param array<string, true> $blocked */
  private function describeBlockedCell(int $x, int $y, array $blocked): string
  {
    return match (true) {
      ! isset($this->collisions[$y][$x]) => 'outside the map',
      isset($blocked[self::key($x, $y)]) => 'where an NPC stands',
      default => 'on a cell the player cannot stand on',
    };
  }

  /** @param ReachabilityEntrance[] $entrances */
  private function isEntranceCell(int $x, int $y, array $entrances): bool
  {
    foreach ($entrances as $entrance) {
      if ($entrance->x === $x && $entrance->y === $y) {
        return true;
      }
    }

    return false;
  }

  /** @return iterable<array{int, int}> */
  private static function cellsOf(Rect $area): iterable
  {
    for ($y = $area->getY(); $y < $area->getY() + $area->getHeight(); $y++) {
      for ($x = $area->getX(); $x < $area->getX() + $area->getWidth(); $x++) {
        yield [$x, $y];
      }
    }
  }

  private static function key(int $x, int $y): string
  {
    return "{$x},{$y}";
  }
}
