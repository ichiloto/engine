<?php

namespace Ichiloto\Engine\Field;

use Closure;

/**
 * Shared faced-cell geometry for object events, NPC talk and reachability.
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

  /** @return array{int, int}|null The adjacent faced cell for a cardinal unit heading. */
  public static function findFacingCell(int $x, int $y, int $dx, int $dy): ?array
  {
    return abs($dx) + abs($dy) === 1 ? [$x + $dx, $y + $dy] : null;
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
    $cell = self::findFacingCell($x, $y, $dx, $dy);
    if ($cell === null) {
      return null;
    }

    for ($distance = 0, [$cx, $cy] = $cell;
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
