<?php

namespace Ichiloto\Engine\Shop;

use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\ShopState;
use Ichiloto\Engine\Util\Stores\ItemStore;

/**
 * What a shop sells and the rates it trades at.
 *
 * One reading of the authored shop data serves every way a shop opens: a
 * shop trigger in the field and the `shop` script command an NPC runs.
 *
 * @package Ichiloto\Engine\Shop
 */
final readonly class ShopOffer
{
  /**
   * @param InventoryItem[] $merchandise The items on sale, priced.
   * @param float $buyRate What the party pays, as a share of each price.
   * @param float $sellRate What the party is paid, as a share of each price.
   */
  public function __construct(
    public array $merchandise = [],
    public float $buyRate = 1.0,
    public float $sellRate = 0.5,
  )
  {
  }

  /**
   * Reads a shop's authored data: `items` (each an `item` reference and an
   * optional `price`), and optional `buyRate` and `sellRate`.
   *
   * @param array<string, mixed>|object $data The authored data, as an array or decoded object.
   * @param ItemStore $itemStore The store the item references resolve through.
   * @throws RequiredFieldException When an entry names no item.
   */
  public static function fromData(array|object $data, ItemStore $itemStore): self
  {
    $data = (array) $data;
    $merchandise = [];

    foreach ((array) ($data['items'] ?? []) as $entry) {
      $entry = (array) $entry;
      $reference = $entry['item'] ?? throw new RequiredFieldException('item');
      $item = $itemStore->get($itemStore->requireDefinitionId(strval($reference), 'loading shop merchandise'));
      assert($item instanceof InventoryItem);

      if (isset($entry['price'])) {
        $item->price = $entry['price'];
      }

      $merchandise[] = $item;
    }

    return new self(
      $merchandise,
      isset($data['buyRate']) ? floatval($data['buyRate']) : 1.0,
      isset($data['sellRate']) ? floatval($data['sellRate']) : 0.5,
    );
  }

  /**
   * Opens the shop over the field.
   *
   * @param GameScene $scene The scene whose field the shop opens over.
   * @return ShopState The open shop, which returns to the field when the player leaves.
   */
  public function open(GameScene $scene): ShopState
  {
    $shopState = new ShopState($scene->fieldState->context);
    $shopState->merchandise = $this->merchandise;
    $shopState->traderBuyRate = $this->buyRate;
    $shopState->traderSellRate = $this->sellRate;
    $scene->setState($shopState);

    return $shopState;
  }
}
