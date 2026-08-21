<?php

namespace Ichiloto\Engine\Events\Interfaces;

/**
 * Identifies a map trigger that may start when the player already occupies
 * its area, including immediately after a map load.
 */
interface AutomaticEventTriggerInterface
{
  public function runsAutomatically(): bool;
}
