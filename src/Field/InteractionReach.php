<?php

namespace Ichiloto\Engine\Field;

use Closure;

/**
 * How far the player reaches to speak to someone: the field, the action
 * prompt and reachability all ask the same question here.
 *
 * The player reaches the cell they face, or the NPC directly behind one
 * faced counter cell. Anything else (floor, a wall, another unoccupied counter cell,
 * the map's edge) ends the reach, so walls stay opaque.
 *
 * @package Ichiloto\Engine\Field
 */
final class InteractionReach
{
  public const int MAX_COUNTER_CELLS = 1;

  /**
   * InteractionReach constructor.
   */
  private function __construct()
  {
  }

  /**
   * Finds the cell of the NPC the player reaches from a cell, facing a
   * cardinal unit heading.
   *
   * @param Closure(int, int): bool $isCounterAt Whether a cell is a counter.
   * @param Closure(int, int): bool $hasNpcAt Whether an NPC stands on a cell.
   * @return array{int, int}|null The NPC's cell, or null when none is reached.
   */
  public static function findTalkCell(int $x, int $y, int $dx, int $dy, Closure $isCounterAt, Closure $hasNpcAt): ?array
  {
    if (abs($dx) + abs($dy) !== 1) {
      return null;
    }

    for ($distance = 0, $cx = $x + $dx, $cy = $y + $dy;
      $distance <= self::MAX_COUNTER_CELLS; $distance++, $cx += $dx, $cy += $dy) {
      if ($hasNpcAt($cx, $cy)) {
        return [$cx, $cy];
      }

      if (! $isCounterAt($cx, $cy)) {
        return null;
      }
    }

    return null;
  }
}
