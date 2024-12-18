<?php

namespace Ichiloto\Engine\Entities\Interfaces;

use Ichiloto\Engine\Entities\Inventory\Accessory;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Inventory\Weapon;

/**
 * The interface for an entity that can equip items.
 *
 * @package Ichiloto\Engine\Entities\Interfaces
 */
interface CanEquip
{
  /**
   * Equips the item.
   *
   * @param Weapon|Armor|Accessory $item The item to equip.
   */
  public function equip(Weapon|Armor|Accessory $item): void;

  /**
   * Determines if the entity can equip the item.
   *
   * @param InventoryItem $item The item to equip.
   * @return bool True if the entity can equip the item, false otherwise.
   */
  public function canEquip(InventoryItem $item): bool;
}