<?php

namespace Ichiloto\Engine\Entities\Inventory;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Entities\Interfaces\InventoryItemInterface;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\ItemStore;
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
    foreach ($items as $index => $item) {
      if ($this->inventoryItems->count() >= $this->capacity) {
        break;
      }

      if (! $item instanceof InventoryItemInterface) {
        throw new InvalidArgumentException('The item must be an instance of ' . InventoryItemInterface::class);
      }

      /** @var InventoryItem $foundItem */
      if ($foundItem = array_find($this->inventoryItems->toArray(), fn(InventoryItem $entry) => $entry->id === $item->id)) {
        $foundItem->quantity += 1;
        continue;
      }

      $this->inventoryItems->add($item);
    }

    QuestManager::current()?->syncCollectObjectives();
  }

  /**
   * Removes items from the inventory. Each passed item decrements the matching
   * stack's quantity by one; the stack is only removed once it is depleted.
   *
   * @param InventoryItemInterface ...$items The items.
   */
  public function removeItems(InventoryItemInterface ...$items): void
  {
    foreach ($items as $item) {
      if ($this->inventoryItems->isEmpty()) {
        break;
      }

      if (! $item instanceof InventoryItemInterface) {
        throw new InvalidArgumentException('The item must be an instance of ' . InventoryItemInterface::class);
      }

      /** @var InventoryItem $foundItem */
      if ($foundItem = array_find($this->inventoryItems->toArray(), fn(InventoryItem $entry) => $entry->id === $item->id)) {
        $foundItem->quantity -= 1;

        if ($foundItem->quantity < 1) {
          $this->inventoryItems->remove($foundItem);
        }
      }
    }

    QuestManager::current()?->syncCollectObjectives();
  }

  /**
   * Returns the quantity currently held for the named inventory item.
   *
   * @param string $itemName The inventory-item name.
   * @return int The available quantity.
   */
  public function getQuantityByName(string $itemName): int
  {
    return $this->getQuantity($itemName, 'checking inventory quantity by display name');
  }

  /** Returns the held quantity for one stable definition id. */
  public function getQuantityById(string $definitionId): int
  {
    return $this->getQuantity($definitionId, 'checking inventory quantity by stable id');
  }

  /** Returns held quantity through the one stable-id/name/alias contract. */
  public function getQuantity(string $reference, string $context = 'checking inventory quantity'): int
  {
    if (! ConfigStore::has(ItemStore::class)) {
      $normalized = strtolower(trim($reference));
      $hasLocalMatch = array_any(
        $this->inventoryItems->toArray(),
        static fn(InventoryItem $item): bool => $item->id === $normalized
          || strtolower(trim($item->name)) === $normalized,
      );

      if (! $hasLocalMatch) {
        // A catalogue-free standalone inventory can still answer that an
        // exact reference is not currently held. Running projects always use
        // ItemStore, where unknown authored references fail closed.
        return 0;
      }
    }

    $definitionId = $this->resolveDefinitionId($reference, $context);
    /** @var InventoryItem|null $foundItem */
    $foundItem = array_find(
      $this->inventoryItems->toArray(),
      static fn(InventoryItem $item): bool => $item->id === $definitionId
    );

    return $foundItem?->quantity ?? 0;
  }

  /**
   * Determines whether the party holds the named key item.
   *
   * @param string $itemName The item name.
   * @return bool True when a key item with that name is held.
   */
  public function hasKeyItem(string $itemName): bool
  {
    $definitionId = $this->resolveDefinitionId($itemName, 'checking a key-item world condition');

    return null !== array_find(
      $this->inventoryItems->toArray(),
      static fn(InventoryItem $item): bool => $item->isKeyItem && $item->id === $definitionId
    );
  }

  /**
   * Consumes the requested quantity of the named inventory item.
   *
   * @param string $itemName The inventory-item name.
   * @param int $quantity The quantity to consume.
   * @return bool True when the quantity was consumed.
   */
  public function consumeQuantity(string $itemName, int $quantity): bool
  {
    return $this->consumeReference($itemName, $quantity, 'consuming inventory by display name');
  }

  /** Consumes a quantity using durable definition identity. */
  public function consumeQuantityById(string $definitionId, int $quantity): bool
  {
    return $this->consumeReference($definitionId, $quantity, 'consuming inventory by stable id');
  }

  /** Consumes through the one stable-id/name/alias contract. */
  public function consumeReference(string $reference, int $quantity, string $context = 'consuming inventory'): bool
  {
    if ($quantity < 1) {
      return true;
    }

    $definitionId = $this->resolveDefinitionId($reference, $context);

    /** @var InventoryItem|null $foundItem */
    $foundItem = array_find(
      $this->inventoryItems->toArray(),
      static fn(InventoryItem $item): bool => $item->id === $definitionId
    );

    if (! $foundItem instanceof InventoryItem || $foundItem->quantity < $quantity) {
      return false;
    }

    $foundItem->quantity -= $quantity;

    if ($foundItem->quantity < 1) {
      $this->inventoryItems->remove($foundItem);
    }

    return true;
  }

  private function resolveDefinitionId(string $reference, string $context): string
  {
    if (ConfigStore::has(ItemStore::class)) {
      $store = ConfigStore::get(ItemStore::class);

      if ($store instanceof ItemStore) {
        return $store->requireDefinitionId($reference, $context);
      }
    }

    // Standalone embedders and unit-level inventories may have no project
    // catalogue. Exact held stable IDs and current names remain deterministic;
    // declared aliases require ItemStore and therefore fail closed here.
    $normalized = strtolower(trim($reference));
    $matches = array_values(array_filter(
      $this->inventoryItems->toArray(),
      static fn(InventoryItem $item): bool => $item->id === $normalized
        || strtolower(trim($item->name)) === $normalized,
    ));
    $ids = array_values(array_unique(array_map(
      static fn(InventoryItem $item): string => $item->id,
      $matches,
    )));

    if (count($ids) !== 1) {
      throw new InvalidArgumentException(sprintf(
        'Inventory reference "%s" cannot be resolved while %s without a project ItemStore.',
        trim($reference),
        $context,
      ));
    }

    return $ids[0];
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
