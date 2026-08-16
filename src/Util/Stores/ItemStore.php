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
  /** @var array<string, string> Normalized display names and aliases to IDs. */
  protected array $aliases = [];

  /**
   * ItemStore constructor.
   */
  public function __construct()
  {
    $items = asset('Data/items.php', true);

    foreach ($items as $item) {
      if ($item instanceof InventoryItem) {
        if (isset($this->items[$item->id])) {
          throw new RuntimeException(sprintf('Duplicate inventory definition id: %s.', $item->id));
        }

        $this->items[$item->id] = $item;
        $this->registerAlias($item->name, $item->id);

        foreach ($item->aliases as $alias) {
          if (is_string($alias)) {
            $this->registerAlias($alias, $item->id);
          }
        }
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

    $id = $this->resolveId($path);
    $definition = ($id !== null ? $this->items[$id] ?? null : null) ?? $default;

    return $definition instanceof InventoryItem ? clone $definition : null;
  }

  /**
   * @inheritDoc
   */
  public function set(string $path, mixed $value): void
  {
    if (! $value instanceof InventoryItem) {
      throw new InvalidArgumentException('The value must be an instance of ' . InventoryItem::class);
    }

    $this->items[$value->id] = clone $value;
    $this->registerAlias($value->name, $value->id);
  }

  /**
   * @inheritDoc
   */
  public function has(string $path): bool
  {
    return $this->resolveId($path) !== null;
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

    $definitionId = $this->resolveId($itemName);
    assert($definitionId !== null);
    $prototype = $this->items[$definitionId];
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

  private function resolveId(string $reference): ?string
  {
    $reference = strtolower(trim($reference));

    if (isset($this->items[$reference])) {
      return $reference;
    }

    return $this->aliases[$reference] ?? null;
  }

  /** Resolves a stable id or declared compatibility/display alias to its id. */
  public function definitionIdFor(string $reference): ?string
  {
    return $this->resolveId($reference);
  }

  private function registerAlias(string $alias, string $definitionId): void
  {
    $alias = strtolower(trim($alias));

    if ($alias === '') {
      return;
    }

    $existing = $this->aliases[$alias] ?? null;

    if ($existing !== null && $existing !== $definitionId) {
      throw new RuntimeException(sprintf('Inventory alias "%s" resolves to multiple definitions.', $alias));
    }

    $this->aliases[$alias] = $definitionId;
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
