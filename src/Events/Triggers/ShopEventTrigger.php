<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Entities\Actions\EnterShopAction;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Messaging\Dialogue\Dialogue;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

/**
 * Represents the shop event trigger.
 *
 * @package Ichiloto\Engine\Events\Triggers
 */
class ShopEventTrigger extends EventTrigger
{
  /**
   * @var Item[] The items in the shop.
   */
  protected(set) array $items = [];
  /**
   * @var Dialogue[] The dialogue of the shop.
   */
  protected(set) array $dialogue = [];

  /**
   * @throws RequiredFieldException
   */
  public function configure(): void
  {
    $itemStore = ConfigStore::get(ItemStore::class);

    foreach ($this->data->items as $itemData) {
      $itemName = $itemData->item ?? throw new RequiredFieldException('item');
      $itemPrice = $itemData->price ?? null;
      /** @var InventoryItem $item */
      if ($item = $itemStore->get($itemName)) {
        if (! is_null($itemPrice)) {
          $item->price = $itemPrice;
        }
        $this->items[] = $item;
      }
    }

    foreach ($this->data->dialogue ?? [] as $dialogue) {
      $this->dialogue[] = Dialogue::fromObject($dialogue);
    }
  }

  /**
   * @inheritDoc
   */
  public function enter(EventTriggerContextInterface $context): void
  {
    parent::enter($context);
    $context->player->erase();
    $context->player->availableAction = new EnterShopAction($this);
    $context->player->render();
  }

  /**
   * @inheritDoc
   */
  public function exit(EventTriggerContextInterface $context): void
  {
    parent::exit($context);
    $context->player->erase();
    $context->player->availableAction = null;
    $context->player->render();
  }
}