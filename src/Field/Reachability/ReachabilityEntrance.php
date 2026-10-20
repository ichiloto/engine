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
   * @param list<array<string, mixed>> $conditions World conditions that hold whenever the arrival happens:
   *   those of the event or NPC that sends the player, so content they exclude is never met on this arrival.
   */
  public function __construct(
    public int $x,
    public int $y,
    public string $source,
    public array $conditions = [],
  )
  {
  }
}
