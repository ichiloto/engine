<?php

namespace Ichiloto\Engine\Income;

/**
 * What advances an income schedule towards its next payment.
 *
 * @package Ichiloto\Engine\Income
 */
enum IncomeCadence: string
{
  /** Seconds of active play: field, battle and story time, never menus, pauses or idle time. */
  case ACTIVE_TIME = 'active_time';
  /** Steps the player walks on field maps. */
  case STEPS = 'steps';
  /** Battles the party wins. */
  case BATTLES = 'battles';
  /** Only the `payday` script command, so a game's own calendar or day cycle decides. */
  case EVENT = 'event';

  /** Whether payments come from a counted interval rather than only from `payday`. */
  public function isCounted(): bool
  {
    return $this !== self::EVENT;
  }

  /** @return string[] */
  public static function values(): array
  {
    return array_map(static fn(self $cadence): string => $cadence->value, self::cases());
  }
}
