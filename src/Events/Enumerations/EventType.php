<?php

namespace Ichiloto\Engine\Events\Enumerations;

enum EventType: string
{
  case ACHIEVEMENT = AchievementEvent::class;
  case GAME = GameEvent::class;
  case KEYBOARD = KeyboardEvent::class;
  case SCENE = SceneEvent::class;
  case TIME = TimeEvent::class;
  case BATTLE = BattleEvent::class;
  case DIALOG = DialogEvent::class;
  case MOVEMENT = MovementEvent::class;
  case MAP = MapEvent::class;
  case MODAL = ModalEvent::class;
  case MENU = MenuEvent::class;
  case GAME_PLAY = GameplayEvent::class;
  case NOTIFICATION = NotificationEventType::class;
}
