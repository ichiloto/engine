<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use RuntimeException;

/**
 * Represents the rewards that a player can get after winning a battle.
 *
 * @package Ichiloto\Engine\Battle
 */
class BattleRewards
{
  /**
   * @var DropItem[] $items
   */
  protected(set) array $items = [];

  /**
   * @var InventoryItem|null $item The first drop candidate to pass its
   *                               drop-rate roll, or null when none pass.
   */
  public ?InventoryItem $item {
    get {
      if (empty($this->items)) {
        return null;
      }

      foreach ($this->items as $item) {
        if ($item->dropRate > 0 && mt_rand() / mt_getrandmax() <= $item->dropRate) {
          return $item->item;
        }
      }

      return null;
    }
  }

  /**
   * Creates a new instance of the rewards.
   *
   * @param int $experience The experience points.
   * @param int $gold The gold.
   * @param DropItem[]|array<array{item: string, rate: float}> $items The items.
   * @throws RequiredFieldException If a required field is missing.
   */
  public function __construct(
    protected(set) int $experience,
    protected(set) int $gold,
    array $items
  )
  {
    // The store resolves item names, so rewards that name no items need no
    // store. Demanding one up front made an empty rewards object impossible
    // to build in authoring tools that have no game running.
    $itemStore = null;

    foreach ($items as $item) {
      if ($item instanceof DropItem) {
        $this->items[] = $item;
      }

      if (is_array($item)) {
        if (! isset($item['item']) ) {
          throw new RequiredFieldException('item');
        }

        if (! isset($item['rate']) ) {
          throw new RequiredFieldException('rate');
        }

        $itemStore ??= ConfigStore::get(ItemStore::class);
        if (! $itemStore instanceof ItemStore) {
          throw new RuntimeException('Item store is not set.');
        }

        if ($dropItem = $itemStore->get($item['item']) ) {
          $this->items[] = new DropItem($dropItem, $item['rate']);
        }
      }
    }
  }
}
