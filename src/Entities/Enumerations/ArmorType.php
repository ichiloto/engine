<?php

namespace Ichiloto\Engine\Entities\Enumerations;

use Ichiloto\Engine\Entities\Interfaces\InventoryTypeInterface;

/**
 * The ArmorType class.
 *
 * @package Ichiloto\Engine\Entities\Enumerations
 */
enum ArmorType: string implements InventoryTypeInterface
{
  case NONE = 'None';
  case GENERAL_ARMOR = 'General Armor';
  case MAGIC_ARMOR = 'Magic Armor';
  case LIGHT_ARMOR = 'Light Armor';
  case HEAVY_ARMOR = 'Heavy Armor';
  case SMALL_SHIELD = 'Small Shield';
  case LARGE_SHIELD = 'Large Shield';
}
