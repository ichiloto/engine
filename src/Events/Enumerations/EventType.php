<?php

namespace Ichiloto\Engine\Events\Enumerations;

use Ichiloto\Engine\Events\AchievementEvent;
use Ichiloto\Engine\Events\GameEvent;
use Ichiloto\Engine\Events\GameplayEvent;
use Ichiloto\Engine\Events\KeyboardEvent;
use Ichiloto\Engine\Events\MapEvent;
use Ichiloto\Engine\Events\MenuEvent;
use Ichiloto\Engine\Events\ModalEvent;
use Ichiloto\Engine\Events\MovementEvent;
use Ichiloto\Engine\Events\NotificationEvent;
use Ichiloto\Engine\Events\SceneEvent;

/**
 * The event types. Each case is backed by the class name of the event it represents.
 *
 * @package Ichiloto\Engine\Events\Enumerations
 */
enum EventType: string
{
  case ACHIEVEMENT = AchievementEvent::class;
  case GAME = GameEvent::class;
  case KEYBOARD = KeyboardEvent::class;
  case SCENE = SceneEvent::class;
  case MOVEMENT = MovementEvent::class;
  case MAP = MapEvent::class;
  case MODAL = ModalEvent::class;
  case MENU = MenuEvent::class;
  case GAME_PLAY = GameplayEvent::class;
  case NOTIFICATION = NotificationEvent::class;
}
