<?php

namespace Ichiloto\Engine\Core\Enumerations;

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
}
