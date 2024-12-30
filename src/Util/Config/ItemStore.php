<?php

namespace Ichiloto\Engine\Util\Config;

use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use PSpell\Config;
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
    return $this->items[$path] ?? null;
  }

  /**
   * @inheritDoc
   */
  public function set(string $path, mixed $value): void
  {
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
   */
  public function load(array $data): array
  {
    $items = [];
    $itemStore = ConfigStore::get(ItemStore::class);

    if (! $itemStore instanceof ItemStore) {
      throw new RuntimeException('Item store not found.');
    }

    foreach ($data as $datum) {
      $itemName = $datum['item'] ?? throw new RequiredFieldException('item');
      $itemPrice = $datum['price'] ?? null;
      $itemQuantity = $datum['quantity'] ?? 1;

      /** @var InventoryItem $item */
      if ($item = $itemStore->get($itemName)) {
        if (! is_null($itemPrice)) {
          $item->price = $itemPrice;
        }
        for ($count = 0; $count < $itemQuantity; $count++) {
          $items[] = $item;
        }
      }
    }

    return $items;
  }
}