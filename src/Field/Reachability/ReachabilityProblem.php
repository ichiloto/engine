<?php

namespace Ichiloto\Engine\Field\Reachability;

/**
 * One way a map's authored layout blocks the player.
 *
 * @package Ichiloto\Engine\Field\Reachability
 */
final readonly class ReachabilityProblem
{
  public function __construct(
    public string $mapId,
    public ReachabilityProblemKind $kind,
    public string $message,
    public ?int $x = null,
    public ?int $y = null,
  )
  {
  }
}
