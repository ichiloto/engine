<?php

namespace Ichiloto\Engine\Entities\Interfaces;

use Ichiloto\Engine\Entities\Inventory\InventoryItem;

/**
 * The interface for an inventory item user.
 *
 * @package Ichiloto\Engine\Entities\Interfaces
 */
interface InventoryItemUserInterface
{
  /**
   * Uses the inventory item.
   *
   * @param InventoryItem $inventoryItem The inventory item to use.
   */
  public function use(InventoryItem $inventoryItem): void;
}