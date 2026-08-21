<?php

namespace Ichiloto\Engine\Messaging\Notifications\Enumerations;

/**
 * Class NotificationDuration. Represents a notification duration.
 *
 * @package Ichiloto\Engine\Messaging\Notifications\Enumerations
 */
enum NotificationDuration: int
{
  case SHORT = 4000;
  case MEDIUM = 6000;
  case LONG = 8000;

  /**
   * Returns the float value of the duration in milliseconds.
   *
   * @return float Returns the float value of the duration in milliseconds.
   */
  public function toFloat(): float
  {
    return (float) $this->value / 1000;
  }
}
