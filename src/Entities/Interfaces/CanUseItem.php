<?php

namespace Ichiloto\Engine\Entities\Interfaces;

use Ichiloto\Engine\Entities\Inventory\InventoryItem;

/**
 * Interface CanUseItem. Represents an entity that can use an item.
 *
 * @package Ichiloto\Engine\Entities\Interfaces
 */
interface CanUseItem
{
  /**
   * Uses an item.
   *
   * @param InventoryItem $item The item to use.
   * @param int $quantity The quantity of the item to use.
   */
  public function use(InventoryItem $item, int $quantity = 1): void;

  /**
   * Checks if an item can be used.
   *
   * @param InventoryItem $item The item to check.
   *
   * @return bool True if the item can be used, false otherwise.
   */
  public function canUseItem(InventoryItem $item): bool;
}