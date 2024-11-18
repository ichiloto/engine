<?php

namespace Ichiloto\Engine\Events\Enumerations;

/**
 * Class NotificationEventType. Represents a notification event type.
 *
 * @package Ichiloto\Engine\Events\Enumerations
 */
enum NotificationEventType
{
  case OPEN;
  case DISMISS;
  case UPDATE;
  case RENDER;
  case ERASE;
  case RESUME;
  case SUSPEND;
}