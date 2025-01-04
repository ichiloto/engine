<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\Inventory\InventoryItem;

/**
 * Represents an item that can be dropped by an enemy.
 *
 * @package Ichiloto\Engine\Battle
 */
class DropItem
{
  /**
   * DropItem constructor.
   *
   * @param InventoryItem $item The item to drop.
   * @param float $dropRate The drop rate of the item.
   */
  public function __construct(
    /**
     * The item to drop.
     */
    protected(set) InventoryItem $item,
    /**
     * The drop rate of the item.
     */
    protected(set) float $dropRate = 0 {
      get {
        return clamp($this->dropRate, 0, 1);
      }
    }
  )
  {
  }
}