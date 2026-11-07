<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interfaces;

/** Declares whether restoring the player inside this trigger merely offers a player action. */
interface ActionOnlyEventTriggerInterface
{
  /**
   * Restoring a player inside the area neither traps them nor runs the action.
   * Read the declaration from authored data without constructing a live trigger.
   *
   * @param array<string, mixed> $data The event's authored data.
   */
  public static function requiresActionKey(array $data): bool;
}
