<?php

namespace Ichiloto\Engine\Field;

use Closure;

/**
 * How far the player reaches to speak to someone: the field, the action
 * prompt and reachability all ask the same question here.
 *
 * The player reaches the cell they face, and on across any number of counter
 * cells in a straight line, to the first NPC. Anything else (floor, a wall,
 * the map's edge) ends the reach, so walls stay opaque.
 *
 * @package Ichiloto\Engine\Field
 */
final class InteractionReach
{
  /**
   * InteractionReach constructor.
   */
  private function __construct()
  {
  }

  /**
   * Finds the cell of the NPC the player reaches from a cell, facing a
   * heading.
   *
   * @param Closure(int, int): bool $isCounterAt Whether a cell is a counter.
   * @param Closure(int, int): bool $hasNpcAt Whether an NPC stands on a cell.
   * @return array{int, int}|null The NPC's cell, or null when none is reached.
   */
  public static function findTalkCell(int $x, int $y, int $dx, int $dy, Closure $isCounterAt, Closure $hasNpcAt): ?array
  {
    if ($dx === 0 && $dy === 0) {
      return null;
    }

    for ($cx = $x + $dx, $cy = $y + $dy; ; $cx += $dx, $cy += $dy) {
      if ($hasNpcAt($cx, $cy)) {
        return [$cx, $cy];
      }

      if (! $isCounterAt($cx, $cy)) {
        return null;
      }
    }
  }
}
