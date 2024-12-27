<?php

namespace Ichiloto\Engine\Util\Config;

use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

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
}