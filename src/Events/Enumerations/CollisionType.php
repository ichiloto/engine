<?php

namespace Ichiloto\Engine\Events\Enumerations;

/**
 * The CollisionType enumeration represents the type of collision.
 *
 * @package Ichiloto\Engine\Events\Enumerations
 */
enum CollisionType: int
{
  case NONE = 0;
  case SOLID = 1;
  case NPC = 2;
  case PLAYER = 3;
  case ITEM = 4;
  case EXIT = 5;
  case SAVE_POINT = 6;
  case ENCOUNTER = 7;
  case COLLECTABLE = 8;
}
