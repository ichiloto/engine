<?php

namespace Ichiloto\Engine\Util\Stores;

use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
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
        $normalizedId = self::normalizeReference($item->id);

        if (isset($this->aliases[$normalizedId]) && $this->aliases[$normalizedId] !== $normalizedId) {
          throw new RuntimeException(sprintf('Inventory definition id "%s" conflicts with a declared alias.', $item->id));
        }

        if (isset($this->items[$normalizedId])) {
          throw new RuntimeException(sprintf('Duplicate inventory definition id: %s.', $item->id));
        }

        $this->items[$normalizedId] = $item;
        $this->registerAlias($item->name, $normalizedId);

        foreach ($item->aliases as $alias) {
          if (is_string($alias)) {
            $this->registerAlias($alias, $normalizedId);
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

    $definitionId = self::normalizeReference($value->id);

    if (isset($this->aliases[$definitionId]) && $this->aliases[$definitionId] !== $definitionId) {
      throw new RuntimeException(sprintf('Inventory definition id "%s" conflicts with a declared alias.', $value->id));
    }

    $this->items[$definitionId] = clone $value;
    $this->registerAlias($value->name, $definitionId);

    foreach ($value->aliases as $alias) {
      if (is_string($alias)) {
        $this->registerAlias($alias, $definitionId);
      }
    }
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
  public function instantiate(string $itemName, int $quantity = 1, string $context = 'instantiating inventory content'): array
  {
    $itemName = trim($itemName);

    $definitionId = $this->requireDefinitionId($itemName, $context);
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
    $reference = self::normalizeReference($reference);

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

  /** Resolves an authored/saved reference or fails closed with its consumer context. */
  public function requireDefinitionId(string $reference, string $context): string
  {
    $id = $this->resolveId($reference);

    if ($id === null) {
      throw new NotFoundException(sprintf(
        'Inventory reference "%s" while %s',
        trim($reference),
        $context,
      ));
    }

    return $id;
  }

  /** Returns the current project display name for any legal reference. */
  public function displayNameFor(string $reference, string $context = 'displaying inventory content'): string
  {
    return $this->items[$this->requireDefinitionId($reference, $context)]->name;
  }

  private function registerAlias(string $alias, string $definitionId): void
  {
    $alias = self::normalizeReference($alias);

    if ($alias === '') {
      return;
    }

    $existing = $this->aliases[$alias] ?? null;

    if (isset($this->items[$alias]) && $alias !== $definitionId) {
      throw new RuntimeException(sprintf('Inventory alias "%s" conflicts with a definition id.', $alias));
    }

    if ($existing !== null && $existing !== $definitionId) {
      throw new RuntimeException(sprintf('Inventory alias "%s" resolves to multiple definitions.', $alias));
    }

    $this->aliases[$alias] = $definitionId;
  }

  private static function normalizeReference(string $reference): string
  {
    return strtolower(trim($reference));
  }

  /**
   * Loads the data.
   *
   * @param array<int, array{item: string, quantity?: int, price?: int}|string> $data The data to load.
   * @return InventoryItem[] The items.
   * @throws NotFoundException Thrown when the item store is not found.
   * @throws RequiredFieldException Thrown when a required field is missing.
   */
  public function load(array $data): array
  {
    $items = [];
    foreach ($data as $datum) {
      $itemName = is_string($datum)
        ? $datum
        : ($datum['item'] ?? throw new RequiredFieldException('item'));
      $itemPrice = is_array($datum) ? ($datum['price'] ?? null) : null;
      $itemQuantity = is_array($datum) ? ($datum['quantity'] ?? 1) : 1;

      $loadedItems = $this->instantiate(
        strval($itemName),
        intval($itemQuantity),
        'loading an authored inventory list',
      );

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
