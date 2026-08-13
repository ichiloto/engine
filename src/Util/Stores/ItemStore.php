<?php

namespace Ichiloto\Engine\Util\Stores;

use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Represents a configuration store for items.
 *
 * @package Ichiloto\Engine\Util\Config
 */
class ItemStore implements ConfigInterface
{
  /**
   * @var array<string, InventoryItem> The items.
   */
  protected array $items = [];

  /**
   * ItemStore constructor.
   */
  public function __construct()
  {
    $items = asset('Data/items.php', true);

    foreach ($items as $item) {
      if ($item instanceof InventoryItem) {
        $key = $item->name;
        $this->items[$key] = $item;
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function get(string $path, mixed $default = null): ?InventoryItem
  {
    if (! $default instanceof InventoryItem) {
      $default = null;
    }

    return $this->items[$path] ?? $default;
  }

  /**
   * @inheritDoc
   */
  public function set(string $path, mixed $value): void
  {
    if (! $value instanceof InventoryItem) {
      throw new InvalidArgumentException('The value must be an instance of ' . InventoryItem::class);
    }

    $this->items[$path] = $value;
  }

  /**
   * @inheritDoc
   */
  public function has(string $path): bool
  {
    return isset($this->items[$path]);
  }

  /**
   * Creates independent inventory instances from one catalog entry.
   *
   * Runtime consumers deal in stable item names, whereas load() hydrates
   * configuration rows. Keeping those contracts separate prevents commands
   * from accidentally passing names into the configuration parser.
   *
   * @param string $itemName The exact catalog name.
   * @param int $quantity The number of independent copies to create.
   * @return InventoryItem[] The cloned inventory instances.
   * @throws NotFoundException When the catalog has no matching entry.
   */
  public function instantiate(string $itemName, int $quantity = 1): array
  {
    $itemName = trim($itemName);

    if ($itemName === '' || ! $this->has($itemName)) {
      throw new NotFoundException(sprintf('Inventory item "%s"', $itemName));
    }

    $prototype = $this->items[$itemName];
    $items = [];

    for ($count = 0; $count < max(0, $quantity); $count++) {
      $items[] = clone $prototype;
    }

    return $items;
  }

  /**
   * @inheritDoc
   */
  public function persist(): void
  {
    // Do nothing
  }

  /**
   * Loads the data.
   *
   * @param array<array{item: string, quantity: int}> $data The data to load.
   * @return InventoryItem[] The items.
   * @throws NotFoundException Thrown when the item store is not found.
   * @throws RequiredFieldException Thrown when a required field is missing.
   */
  public function load(array $data): array
  {
    $items = [];
    $itemStore = ConfigStore::get(ItemStore::class);

    if (! $itemStore instanceof ItemStore) {
      throw new NotFoundException(ItemStore::class);
    }

    foreach ($data as $datum) {
      $itemName = $datum['item'] ?? throw new RequiredFieldException('item');
      $itemPrice = $datum['price'] ?? null;
      $itemQuantity = $datum['quantity'] ?? 1;

      $loadedItems = $itemStore->instantiate($itemName, $itemQuantity);

      if (! is_null($itemPrice)) {
        foreach ($loadedItems as $item) {
          $item->price = $itemPrice;
        }
      }

      $items = [...$items, ...$loadedItems];
    }

    return $items;
  }
}
