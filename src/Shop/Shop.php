<?php

namespace Ichiloto\Engine\Shop;

use Assegai\Collections\ItemList;
use Exception;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Party as Trader;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * A shop where players can buy and sell items.
 *
 * @package Ichiloto\Engine\Shop
 */
class Shop
{
  /**
   * @var Inventory The shop's inventory.
   */
  protected(set) Inventory $inventory;

  /**
   * Creates a new shop instance.
   *
   * @param InventoryItem[] $merchandise The merchandise to stock the shop with.
   */
  public function __construct(
    array $merchandise = []
  )
  {
    $this->inventory = new Inventory(new ItemList(InventoryItem::class, $merchandise));
  }

  /**
   * Sell an item to a trader.
   *
   * @param InventoryItem $item The item to sell.
   * @param int $quantity The quantity of the item to sell.
   * @param Trader $trader The trader to sell to.
   * @return void
   * @throws Exception If an error occurs while alerting the player.
   */
  public function sell(InventoryItem $item, int $quantity, Trader $trader): void
  {
    $totalCost = $item->price * $quantity;

    if ($trader->accountBalance < $totalCost) {
      alert('Not enough ' . config(ProjectConfig::class, 'vocab.currency.name', 'Gold') . '!');
      return;
    }

    if ($foundItem = $trader->inventory->items->find(fn(InventoryItem $inventoryItem) => $item->name === $inventoryItem->name)) {
      if ($foundItem->quantity + $quantity > $foundItem->maxQuantity) {
        alert('Not enough space in inventory!');
        return;
      }
    }

    for($count = 0; $count < $quantity; $count++) {
      $trader->inventory->addItems($item);
    }

    $trader->debit($totalCost);
  }

  /**
   * Buy an item from a trader.
   *
   * @param InventoryItem $item The item to buy.
   * @param int $quantity The quantity of the item to buy.
   * @param Trader $trader The trader to buy from.
   * @return void
   */
  public function buy(InventoryItem $item, int $quantity, Trader $trader): void
  {
    $totalPayout = $item->price * $quantity * .5;

    for($count = 0; $count < $quantity; $count++) {
      $trader->inventory->removeItems($item);
    }

    $trader->credit($totalPayout);
  }
}