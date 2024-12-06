<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Entities\Interfaces\InventoryItemInterface;
use Ichiloto\Engine\Entities\Inventory\Item\Item;
use InvalidArgumentException;

/**
 * The Inventory class.
 *
 * @package Ichiloto\Engine\Entities\Inventory
 */
class Inventory
{
  /**
   * @var ItemList<Item> The items in the inventory.
   */
  public ItemList $items {
    get {
      return $this->inventoryItems->filter(fn(InventoryItemInterface $item) => $item instanceof Item);
    }
  }

  /**
   * @var ItemList<Weapon> The weapons in the inventory.
   */
  public ItemList $weapons {
    get {
      return $this->inventoryItems->filter(fn(InventoryItemInterface $item) => $item instanceof Weapon);
    }
  }

  /**
   * @var ItemList<Armor> The armor in the inventory.
   */
  public ItemList $armor {
    get {
      return $this->inventoryItems->filter(fn(InventoryItemInterface $item) => $item instanceof Armor);
    }
  }

  /**
   * @var ItemList<Accessory> The accessories in the inventory.
   */
  public ItemList $accessories {
    get {
      return $this->inventoryItems->filter(fn(InventoryItemInterface $item) => $item instanceof Accessory);
    }
  }

  /**
   * The Inventory constructor.
   *
   * @param ItemList $inventoryItems The inventory items.
   */
  public function __construct(
    protected ItemList $inventoryItems = new ItemList(InventoryItemInterface::class)
  )
  {
  }

  /**
   * Adds items to the inventory.
   *
   * @param InventoryItemInterface ...$items The items.
   */
  public function addItems(InventoryItemInterface ...$items): void
  {
    foreach ($items as $item) {
      if (! $item instanceof InventoryItemInterface) {
        throw new InvalidArgumentException('The item must be an instance of ' . InventoryItemInterface::class);
      }

      if ($this->inventoryItems->contains($item)) {
        $foundItem = $this->inventoryItems->find(fn(InventoryItemInterface $entry) => $entry->equals($item));
        $foundItem->quantity += $item->quantity;
      }

      $this->inventoryItems->add($item);
    }
  }

  /**
   * Adds items to the inventory.
   *
   * @param InventoryItemInterface ...$items The items.
   */
  public function removeItems(InventoryItemInterface ...$items): void
  {
    foreach ($items as $item) {
      if (! $item instanceof InventoryItemInterface) {
        throw new InvalidArgumentException('The item must be an instance of ' . InventoryItemInterface::class);
      }

      if ($this->inventoryItems->contains($item)) {
        $foundItem = $this->inventoryItems->find(fn(InventoryItemInterface $entry) => $entry->equals($item));
        $foundItem->quantity -= $item->quantity;
      }

      $this->inventoryItems->remove($item);
    }
  }
}