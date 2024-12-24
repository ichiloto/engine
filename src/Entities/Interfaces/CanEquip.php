<?php

namespace Ichiloto\Engine\Entities\Interfaces;

use Ichiloto\Engine\Entities\EquipmentSlot;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;

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
   * @param Equipment $equipment The item to equip.
   */
  public function equip(Equipment $equipment): void;

  /**
   * Determines if the entity can equip the item.
   *
   * @param InventoryItem $item The item to equip.
   * @return bool True if the entity can equip the item, false otherwise.
   */
  public function canEquip(InventoryItem $item): bool;

  /**
   * Unequips the item.
   *
   * @param EquipmentSlot $slot The slot to unequip.
   */
  public function unequip(EquipmentSlot $slot): void;
}