<?php

namespace Ichiloto\Engine\Entities\Enumerations;

use Ichiloto\Engine\Entities\Interfaces\InventoryTypeInterface;

/**
 * The ElementType class.
 *
 * @package Ichiloto\Engine\Entities\Enumerations
 */
enum ElementType: string implements InventoryTypeInterface
{
  case PHYSICAL = 'Physical';
  case FIRE = 'Fire';
  case ICE = 'Ice';
  case THUNDER = 'Thunder';
  case WATER = 'Water';
  case EARTH = 'Earth';
  case WIND = 'Wind';
  case LIGHT = 'Light';
  case DARKNESS = 'Darkness';
}
