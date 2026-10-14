<?php

namespace Ichiloto\Engine\Core\Enumerations;

use Ichiloto\Engine\Core\Vector2;

/**
 * Class MovementHeading. Represents the heading of a movement.
 *
 * @package Ichiloto\Engine\Core\Enumerations
 */
enum MovementHeading: string
{
  case NONE = 'None';
  case NORTH = 'North';
  case EAST = 'East';
  case SOUTH = 'South';
  case WEST = 'West';

  /**
   * Returns the one-tile direction this heading points, or zero for NONE.
   *
   * @return Vector2 The cardinal direction vector.
   */
  public function getDirection(): Vector2
  {
    return match ($this) {
      self::NORTH => new Vector2(0, -1),
      self::SOUTH => new Vector2(0, 1),
      self::EAST => new Vector2(1, 0),
      self::WEST => new Vector2(-1, 0),
      self::NONE => new Vector2(0, 0),
    };
  }
}
