<?php

namespace Ichiloto\Engine\Messaging\Notifications\Enumerations;

/**
 * Class NotificationDuration. Represents a notification duration.
 *
 * @package Ichiloto\Engine\Messaging\Notifications\Enumerations
 */
enum NotificationDuration: int
{
  case SHORT = 1500;
  case MEDIUM = 3000;
  case LONG = 5000;

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
