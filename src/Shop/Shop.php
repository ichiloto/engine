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
    array $merchandise = [],
    protected(set) float $traderBuyRate = 1.0,
    protected(set) float $traderSellRate = 0.5
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
    if ($quantity < 1) {
      return;
    }

    $totalCost = (int) round($item->price * $quantity * $this->traderBuyRate);

    if ($trader->accountBalance < $totalCost) {
      alert('Not enough ' . config(ProjectConfig::class, 'vocab.currency.name', 'Gold') . '!');
      return;
    }

    $foundItem = $trader->inventory->all->find(fn(InventoryItem $inventoryItem) => $item->id === $inventoryItem->id);
    $remainingSpace = $foundItem instanceof InventoryItem
      ? $foundItem->maxQuantity - $foundItem->quantity
      : ($trader->inventory->isFull ? 0 : $item->maxQuantity);
    if ($quantity > $remainingSpace) {
      alert('Not enough space in inventory!');
      return;
    }

    // A catalogue entry is not an owned stack. Its quantity never controls a purchase.
    $purchasedItem = clone $item;
    $purchasedItem->quantity = 1;
    for($count = 0; $count < $quantity; $count++) {
      $trader->inventory->addItems($purchasedItem);
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
    if ($quantity < 1) {
      return;
    }

    // The offer identifies a stack; only the owned definition controls the sale.
    $ownedItem = array_find(
      $trader->inventory->all->toArray(),
      static fn(InventoryItem $entry): bool => $entry->id === $item->id,
    );
    if (! $ownedItem instanceof InventoryItem || ! $ownedItem->isSellable || $ownedItem->quantity < $quantity) {
      return;
    }

    $totalPayout = (int) round($ownedItem->price * $quantity * $this->traderSellRate);

    for($count = 0; $count < $quantity; $count++) {
      $trader->inventory->removeItems($ownedItem);
    }

    $trader->credit($totalPayout);
  }
}
