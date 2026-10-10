<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Entities\Actions\EnterShopAction;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Messaging\Dialogue\Dialogue;
use Ichiloto\Engine\Shop\ShopOffer;
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
   * @var ShopOffer What the shop sells and its rates, shared with the `shop` script command.
   */
  protected(set) ShopOffer $offer;
  /**
   * @var Item[] The items in the shop.
   */
  public array $items {
    get => $this->offer->merchandise;
  }
  /**
   * @var Dialogue[] The dialogue of the shop.
   */
  protected(set) array $dialogue = [];
  /**
   * @var float The buy rate.
   */
  public float $buyRate {
    get => $this->offer->buyRate;
  }
  /**
   * @var float The sell rate.
   */
  public float $sellRate {
    get => $this->offer->sellRate;
  }

  /**
   * @throws RequiredFieldException
   */
  public function configure(): void
  {
    $this->offer = ShopOffer::fromData($this->data, ConfigStore::get(ItemStore::class));

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
