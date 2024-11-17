<?php

namespace Ichiloto\Engine\Events;

/**
 * GameplayEventType is an enumeration of all game play event types.
 *
 * @package Ichiloto\Engine\Events
 */
enum GameplayEventType
{
  case GAME_OVER;
  case GAME_CONTINUE;
  case PLAYER_TRANSFER;
  case PLAYER_ENCOUNTER;
  case PLAYER_MOVE;
}
