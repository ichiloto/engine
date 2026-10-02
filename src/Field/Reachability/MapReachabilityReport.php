<?php

namespace Ichiloto\Engine\Field\Reachability;

use Ichiloto\Engine\Core\Rect;

/**
 * The cells a player can reach on one map and the problems that leaves.
 *
 * @package Ichiloto\Engine\Field\Reachability
 */
final readonly class MapReachabilityReport
{
  /**
   * @param array<string, true> $reachableCells Reachable cells keyed "x,y".
   * @param ReachabilityProblem[] $problems
   */
  public function __construct(
    public string $mapId,
    private array $reachableCells,
    public array $problems,
  )
  {
  }

  /** Whether the player can stand on a cell. */
  public function isReachable(int $x, int $y): bool
  {
    return isset($this->reachableCells["{$x},{$y}"]);
  }

  /** Whether the player can stand on any cell of an area. */
  public function isAnyReachable(Rect $area): bool
  {
    for ($y = $area->getY(); $y < $area->getY() + $area->getHeight(); $y++) {
      for ($x = $area->getX(); $x < $area->getX() + $area->getWidth(); $x++) {
        if ($this->isReachable($x, $y)) {
          return true;
        }
      }
    }

    return false;
  }

  /** Whether the player can stand beside a cell, as they must to speak to an NPC on it. */
  public function isBesideReachable(int $x, int $y): bool
  {
    return $this->isReachable($x, $y - 1) || $this->isReachable($x + 1, $y)
      || $this->isReachable($x, $y + 1) || $this->isReachable($x - 1, $y);
  }
}
