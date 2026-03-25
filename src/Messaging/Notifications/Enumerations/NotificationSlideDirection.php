<?php

namespace Ichiloto\Engine\Messaging\Notifications\Enumerations;

/**
 * Defines the available slide directions for notification motion.
 *
 * @package Ichiloto\Engine\Messaging\Notifications\Enumerations
 */
enum NotificationSlideDirection: string
{
  case NONE = 'none';
  case LEFT = 'left';
  case RIGHT = 'right';
  case UP = 'up';
  case DOWN = 'down';
}
