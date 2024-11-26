<?php

namespace Ichiloto\Engine\Events\Enumerations;

/**
 * Represents a movement event type.
 *
 * @package Ichiloto\Engine\Events\Enumerations
 */
enum MovementEventType
{
  case TRANSFER_PLAYER;
  case PLAYER_MOVE;
  case PLAYER_STOP;
  case VEHICLE_ENTER;
  case VEHICLE_EXIT;
  case VEHICLE_MOVE;
  case VEHICLE_STOP;
  case SET_VEHICLE_LOCATION;
}
