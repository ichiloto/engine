<?php

namespace Ichiloto\Engine\Events\Interfaces;

/**
 * Identifies a map trigger that may start when the player already occupies
 * its area, including immediately after a map load.
 */
interface AutomaticEventTriggerInterface
{
  /** Whether this trigger already owns the running event session. */
  public bool $sessionIsActive { get; }

  public function runsAutomatically(): bool;

  /**
   * Reads from an event's authored data whether it runs without the action
   * key, so map analysis can tell without building the trigger.
   *
   * @param array<string, mixed> $data The event's authored data.
   */
  public static function determineRunsAutomatically(array $data): bool;
}
