<?php

namespace Ichiloto\Engine\Entities\Actions;

use Exception;
use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Events\Triggers\ShopEventTrigger;
use Ichiloto\Engine\Scenes\Game\States\ShopState;

/**
 * EnterShopAction class. This class is used to enter a shop.
 *
 * @package Ichiloto\Engine\Entities\Actions
 */
class EnterShopAction extends FieldAction
{
  /**
   * EnterShopAction constructor.
   *
   * @param ShopEventTrigger $trigger The shop event trigger.
   */
  public function __construct(
    protected ShopEventTrigger $trigger
  )
  {
  }

  /**
   * @inheritDoc
   * @throws Exception If an error occurs while showing the dialogue.
   */
  public function execute(ActionContextInterface $context): void
  {
    $scene = $context->scene;
    $shopState = new ShopState($scene->fieldState->context);
    $shopState->merchandise = $this->trigger->items;
    $shopState->traderBuyRate = $this->trigger->buyRate;
    $shopState->traderSellRate = $this->trigger->sellRate;

    foreach ($this->trigger->dialogue ?? [] as $dialogue) {
      $dialogue->show();
    }
    $scene->setState($shopState);
  }
}