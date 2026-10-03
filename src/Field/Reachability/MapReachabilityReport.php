<?php

namespace Ichiloto\Engine\Field\Reachability;

use Ichiloto\Engine\Core\CellArea;
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
   * @param array<string, true> $spokenToCells Cells of NPCs the player can speak to, keyed "x,y".
   */
  public function __construct(
    public string $mapId,
    private array $reachableCells,
    public array $problems,
    private array $spokenToCells = [],
  )
  {
  }

  /** Whether the player can stand on a cell. */
  public function isReachable(int $x, int $y): bool
  {
    return isset($this->reachableCells["{$x},{$y}"]);
  }

  /** Whether the player can stand on any cell of an area. */
  public function isAnyReachable(Rect|CellArea $area): bool
  {
    $area = $area instanceof Rect ? CellArea::fromRect($area) : $area;

    foreach ($area->cells as [$x, $y]) {
      if ($this->isReachable($x, $y)) {
        return true;
      }
    }

    return false;
  }

  /** Whether the player can speak to the NPC on a cell, beside it or across counters. */
  public function canSpeakTo(int $x, int $y): bool
  {
    return isset($this->spokenToCells["{$x},{$y}"]);
  }
}
