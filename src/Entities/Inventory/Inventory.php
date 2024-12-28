<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Entities\Interfaces\InventoryItemInterface;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;

/**
 * The Inventory class.
 *
 * @package Ichiloto\Engine\Entities\Inventory
 */
class Inventory
{
  /**
   * The default capacity of the inventory.
   */
  public const int DEFAULT_CAPACITY = 99;

  /**
   * @var ItemList<Item> The items in the inventory.
   */
  public ItemList $items {
    get {
      return $this->inventoryItems->filter(fn(InventoryItemInterface $item) => $item instanceof Item);
    }
  }
  /**
   * @var ItemList<Equipment> The equipment in the inventory.
   */
  public ItemList $equipment {
    get {
      return $this->inventoryItems->filter(fn(InventoryItemInterface $item) => $item instanceof Equipment);
    }
  }

  /**
   * @var ItemList<InventoryItem> The key items in the inventory.
   */
  public ItemList $keyItems {
    get {
      return $this->inventoryItems->filter(fn(InventoryItemInterface $item) => $item->isKeyItem);
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
   * @var ItemList The inventory items.
   */
  public ItemList $all {
    get {
      return $this->inventoryItems;
    }
  }
  /**
   * @var bool Whether the inventory is empty.
   */
  public bool $isEmpty {
    get {
      return $this->inventoryItems->isEmpty();
    }
  }
  /**
   * @var bool Whether the inventory is full.
   */
  public bool $isFull {
    get {
      return $this->inventoryItems->count() >= $this->capacity;
    }
  }
  /**
   * @var bool Whether the inventory is not empty.
   */
  public bool $isNotEmpty {
    get {
      return ! $this->isEmpty;
    }
  }

  /**
   * The Inventory constructor.
   *
   * @param ItemList $inventoryItems The inventory items.
   */
  public function __construct(
    protected ItemList $inventoryItems = new ItemList(InventoryItemInterface::class),
    protected int $capacity = self::DEFAULT_CAPACITY
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
      if ($this->inventoryItems->count() >= $this->capacity) {
        return;
      }

      if (! $item instanceof InventoryItemInterface) {
        throw new InvalidArgumentException('The item must be an instance of ' . InventoryItemInterface::class);
      }

      /** @var InventoryItem $foundItem */
      if ($foundItem = array_find($this->inventoryItems->toArray(), fn(InventoryItem $entry) => $entry->name === $item->name)) {
        $foundItem->quantity += 1;
        return;
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
      if ($this->inventoryItems->isEmpty()) {
        return;
      }

      if (! $item instanceof InventoryItemInterface) {
        throw new InvalidArgumentException('The item must be an instance of ' . InventoryItemInterface::class);
      }

      /** @var InventoryItem $foundItem */
      if ($foundItem = array_find($this->inventoryItems->toArray(), fn(InventoryItem $entry) => $entry->name === $item->name)) {
        $foundItem->quantity -= 1;
        if ($item->quantity > 0) {
          return;
        }
      }

      $this->inventoryItems->remove($item);
    }
  }

  /**
   * Sorts the inventory.
   *
   * @return void
   */
  public function sort(): void
  {
    $items = $this->items->toArray();
    usort($items, 'compare_items');

    $weapons = $this->weapons->toArray();
    usort($weapons, 'compare_items');

    $armor = $this->armor->toArray();
    usort($armor, 'compare_items');

    $accessories = $this->accessories->toArray();
    usort($accessories, 'compare_items');

    $this->inventoryItems->clear();

    if ($items) {
      $this->addItems(...$items);
    }

    if ($weapons) {
      $this->addItems(...$weapons);
    }

    if ($armor) {
      $this->addItems(...$armor);
    }

    if ($accessories) {
      $this->addItems(...$accessories);
    }
  }
}