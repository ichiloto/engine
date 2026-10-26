<?php

namespace Ichiloto\Engine\Field\Reachability;

/**
 * A cell where the player arrives on a map: a transfer or edge trigger into
 * it, a scripted transfer, a sleep spawn point or the game's start.
 *
 * @package Ichiloto\Engine\Field\Reachability
 */
final readonly class ReachabilityEntrance
{
  /**
   * @param string $source Where the arrival is authored, for diagnostics.
   */
  public function __construct(
    public int $x,
    public int $y,
    public string $source,
  )
  {
  }
}
