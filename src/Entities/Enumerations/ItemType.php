<?php

namespace Ichiloto\Engine\Entities\Enumerations;

use Ichiloto\Engine\Entities\Interfaces\InventoryTypeInterface;

/**
 * The ItemType class.
 *
 * @package Ichiloto\Engine\Entities\Enumerations
 */
enum ItemType: string implements InventoryTypeInterface
{
  case REGULAR_ITEM = 'Regular Item';
  case KEY_ITEM = 'Key Item';
  case HIDDEN_ITEM = 'Hidden Item';
}
