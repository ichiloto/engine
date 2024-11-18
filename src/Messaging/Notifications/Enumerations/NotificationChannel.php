<?php

namespace Ichiloto\Engine\Messaging\Notifications\Enumerations;

/**
 * Class NotificationChannel. Represents a notification channel.
 *
 * @package Ichiloto\Engine\Messaging\Notifications\Enumerations
 */
enum NotificationChannel: string
{
  case ACHIEVEMENT = 'ACHIEVEMENT';
  case SYSTEM = 'SYSTEM';
  case INFO = 'INFO';
  case ERROR = 'ERROR';
  case WARNING = 'WARNING';
  case DEBUG = 'DEBUG';
}
