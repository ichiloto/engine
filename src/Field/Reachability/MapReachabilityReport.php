<?php

namespace Ichiloto\Engine\Field\Reachability;

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
}
