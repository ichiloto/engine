<?php

namespace Ichiloto\Engine\Field\Reachability;

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\WorldConditionExclusion;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\Enumerations\EventEngagement;
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
 * through. An event needs a reachable standing cell inside its area or facing
 * an adjacent object cell, according to its engagement; a talkable NPC needs a cell that
 * reaches it, beside it or across one counter cell (InteractionReach, as the
 * field talks).
 *
 * Story conditions are not evaluated. An NPC that only appears under
 * conditions is a story gate, like a conditional event: gates are assumed
 * open, so only an NPC present in every story state blocks its cell. A
 * conditional NPC is still spoken to while present, so it still needs a
 * reachable cell beside it or across one counter cell.
 *
 * @package Ichiloto\Engine\Field\Reachability
 */
final readonly class MapReachability
{
  /**
   * Steps a player may walk on from first reaching an interaction's subject
   * to where it answers before the approach counts as walking round it.
   */
  public const int APPROACH_DETOUR_TOLERANCE = 1;

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
    $blocked = $this->getFixedNpcCells();
    $problems = [];
    $starts = [];

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
      }

      // Movement checks only the destination, so a player placed on a
      // blocked cell can still step off it; one outside the map cannot.
      if (isset($this->collisions[$entrance->y][$entrance->x])) {
        $starts[] = [$entrance->x, $entrance->y];
      }
    }

    $reachable = array_map(static fn(): bool => true, $this->measureWalks($starts, $blocked));

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

  /**
   * Finds interactions the player meets from a side where they do not answer.
   *
   * From each arrival, the player walks the fewest steps to the event's
   * subject: onto or beside its cells from any side, or to a cell where they
   * can speak to one of its speakers. If the nearest cell where the event
   * answers (its engagement and facing) is more than
   * {@see self::APPROACH_DETOUR_TOLERANCE} steps further, the player has
   * reached the subject behind or beside it and must walk round to use it.
   * Arrivals whose conditions exclude the event's are skipped, and the
   * speakers present while the event can run block their cells. One problem
   * is reported per event, naming the arrival with the longest walk round.
   *
   * @param ReachabilityEntrance[] $entrances Where the player arrives.
   * @return ReachabilityProblem[]
   */
  public function findDetouredApproaches(array $entrances): array
  {
    $problems = [];

    foreach ($this->events as $event) {
      if (! $event->isInteraction) {
        continue;
      }

      $blocked = $this->getFixedNpcCells();
      $speakers = [];
      $contact = [];

      foreach ($event->getContactArea()->cells as [$x, $y]) {
        $contact[self::key($x, $y)] = null;
      }

      foreach ($this->npcs as $npc) {
        if ($npc->id === null || $npc->wanders || ! in_array($npc->id, $event->speakers, true)
          || WorldConditionExclusion::proveExclusive($event->conditions, $npc->conditions)) {
          continue;
        }

        $blocked[self::key($npc->x, $npc->y)] = true;

        foreach ($this->getTalkCells($npc) as [$x, $y]) {
          if (! array_key_exists(self::key($x, $y), $contact)) {
            $contact[self::key($x, $y)] = $npc->name;
          }
        }
      }

      $uses = array_map(static fn(array $cell): string => self::key(...$cell), $event->getStandingArea()->cells);
      $worst = null;
      $detoured = 0;

      foreach ($entrances as $entrance) {
        if (! isset($this->collisions[$entrance->y][$entrance->x])
          || WorldConditionExclusion::proveExclusive($event->conditions, $entrance->conditions)) {
          continue;
        }

        $steps = $this->measureWalks([[$entrance->x, $entrance->y]], $blocked);
        $use = self::findNearest($uses, $steps);
        $natural = self::findNearest(array_keys($contact), $steps);

        if ($use === null || $natural === null || $steps[$use] - $steps[$natural] <= self::APPROACH_DETOUR_TOLERANCE) {
          continue;
        }

        $detoured++;
        $detour = $steps[$use] - $steps[$natural];

        if ($worst === null || $detour > $worst['detour']) {
          $worst = ['entrance' => $entrance, 'detour' => $detour, 'natural' => $natural, 'use' => $use];
        }
      }

      if ($worst === null) {
        continue;
      }

      $at = $event->area->firstCell;
      [$naturalX, $naturalY] = array_map(intval(...), explode(',', $worst['natural']));
      [$useX, $useY] = array_map(intval(...), explode(',', $worst['use']));
      $speaker = $contact[$worst['natural']];
      $problems[] = new ReachabilityProblem($this->mapId, ReachabilityProblemKind::DETOURED_APPROACH,
        sprintf('Arriving from %s, the player first reaches event %s (%s) at (%d, %d) on (%d, %d)%s, where it does not answer, and walks %d more %s round to (%d, %d) to use it%s.',
          $worst['entrance']->source, $event->marker ?? '?', basename(str_replace('\\', '/', $event->class)), $at->x, $at->y,
          $naturalX, $naturalY, $speaker === null ? '' : ", beside its speaker {$speaker}",
          $worst['detour'], $worst['detour'] === 1 ? 'step' : 'steps', $useX, $useY,
          $detoured > 1 ? sprintf(' (%d arrivals meet it this way)', $detoured) : ''),
        (int) $at->x, (int) $at->y);
    }

    return $problems;
  }

  /**
   * Counts the fewest steps from the starting cells to every cell the player
   * can walk to, under the field's movement rules.
   *
   * @param list<array{int, int}> $starts Cells on the map where the player arrives.
   * @param array<string, true> $blocked Cells NPCs stand on.
   * @return array<string, int> Steps keyed "x,y".
   */
  private function measureWalks(array $starts, array $blocked): array
  {
    $exits = [];

    foreach ($this->getExitAreas() as $area) {
      foreach ($area->cells as [$x, $y]) {
        $exits[self::key($x, $y)] = true;
      }
    }

    $steps = [];
    $queue = [];
    $arrivals = [];

    foreach ($starts as [$x, $y]) {
      $key = self::key($x, $y);
      $arrivals[$key] = true;

      if (! isset($steps[$key])) {
        $steps[$key] = 0;
        $queue[] = [$x, $y];
      }
    }

    for ($index = 0; $index < count($queue); $index++) {
      [$x, $y] = $queue[$index];

      // Arriving on an exit is allowed; stepping onto one leaves the map.
      if (isset($exits[self::key($x, $y)]) && ! isset($arrivals[self::key($x, $y)])) {
        continue;
      }

      foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$dx, $dy]) {
        $nx = $x + $dx;
        $ny = $y + $dy;
        $key = self::key($nx, $ny);

        if (! isset($steps[$key]) && $this->canStandOn($nx, $ny, $blocked)) {
          $steps[$key] = $steps[self::key($x, $y)] + 1;
          $queue[] = [$nx, $ny];
        }
      }
    }

    return $steps;
  }

  /**
   * @param list<string> $cells Candidate cells keyed "x,y", in a stable order.
   * @param array<string, int> $steps
   * @return string|null The first of the cells the fewest steps away, or null when none is reached.
   */
  private static function findNearest(array $cells, array $steps): ?string
  {
    $nearest = null;

    foreach ($cells as $cell) {
      if (isset($steps[$cell]) && ($nearest === null || $steps[$cell] < $steps[$nearest])) {
        $nearest = $cell;
      }
    }

    return $nearest;
  }

  /** @return array<string, true> Cells fixed NPCs present in every story state stand on. */
  private function getFixedNpcCells(): array
  {
    $blocked = [];

    foreach ($this->npcs as $npc) {
      if (! $npc->wanders && ! $npc->isConditional) {
        $blocked[self::key($npc->x, $npc->y)] = true;
      }
    }

    return $blocked;
  }

  /** @return CellArea[] Areas that take the player off the map. */
  private function getExitAreas(): array
  {
    $areas = array_map(static fn(MapTrigger $trigger): CellArea => CellArea::fromRect($trigger->area), $this->edgeTriggers);

    foreach ($this->events as $event) {
      // Standing on any walkable cell of an exit leaves the map, a faced object's included;
      // a solid object cell is never reached, so including it changes nothing.
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
    $isAnyReachable = static function (CellArea $area) use ($reachable): bool {
      foreach ($area->cells as [$x, $y]) {
        if (isset($reachable[self::key($x, $y)])) {
          return true;
        }
      }

      return false;
    };

    foreach ($this->events as $event) {
      if (! $isAnyReachable($event->getStandingArea())) {
        $at = $event->area->firstCell;
        $problems[] = new ReachabilityProblem($this->mapId, ReachabilityProblemKind::UNREACHABLE_EVENT,
          sprintf('No %s of event %s (%s) at (%d, %d) can be reached, so it never fires.',
            $event->engagement === EventEngagement::FACING ? 'allowed approach cell' : 'cell',
            $event->marker ?? '?', basename(str_replace('\\', '/', $event->class)), $at->x, $at->y),
          (int) $at->x, (int) $at->y);
      }
    }

    foreach ($this->edgeTriggers as $trigger) {
      if (! $isAnyReachable(CellArea::fromRect($trigger->area))) {
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
          sprintf('NPC %s at (%d, %d) can never be spoken to: no reachable cell is beside it or directly across one counter cell from it.',
            $npc->name, $npc->x, $npc->y),
          $npc->x, $npc->y);
      }
    }

    return $problems;
  }

  /**
   * Whether a bounded candidate standing cell reaches this NPC under the
   * field's own predicate. The shared bound and predicate also govern the
   * authoring boundary; no reverse unbounded counter traversal is allowed.
   *
   * @param array<string, true> $reachable
   */
  private function isSpokenToFrom(NpcPlacement $npc, array $reachable): bool
  {
    return array_any($this->getTalkCells($npc), static fn(array $cell): bool => isset($reachable[self::key(...$cell)]));
  }

  /** @return list<array{int, int}> Cells from which the player speaks to this NPC, beside it or across one counter cell. */
  private function getTalkCells(NpcPlacement $npc): array
  {
    $occupied = [];

    foreach ($this->npcs as $other) {
      $occupied[self::key($other->x, $other->y)] = true;
    }

    $isCounterAt = fn(int $x, int $y): bool => ($this->collisions[$y][$x] ?? null) === CollisionType::COUNTER->value;
    $hasNpcAt = static fn(int $x, int $y): bool => isset($occupied[self::key($x, $y)]);
    $cells = [];

    foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$dx, $dy]) {
      for ($distance = 1; $distance <= InteractionReach::MAX_COUNTER_CELLS + 1; $distance++) {
        $x = $npc->x + $dx * $distance;
        $y = $npc->y + $dy * $distance;
        if (InteractionReach::findTalkCell($x, $y, -$dx, -$dy, $isCounterAt, $hasNpcAt) === [$npc->x, $npc->y]) {
          $cells[] = [$x, $y];
        }
      }
    }

    return $cells;
  }

  /** @param array<string, true> $blocked */
  private function canStandOn(int $x, int $y, array $blocked): bool
  {
    $collision = $this->collisions[$y][$x] ?? null;
    // Map loading validates cells; direct analysis retains its tolerance of unknown non-null values.
    $collisionType = is_int($collision) ? CollisionType::tryFrom($collision) : null;

    return $collision !== null
      && $collisionType?->blocksMovement() !== true
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

  private static function key(int $x, int $y): string
  {
    return "{$x},{$y}";
  }
}
