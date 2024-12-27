<?php

namespace Ichiloto\Engine\Entities\Actions;

use Exception;
use Ichiloto\Engine\Entities\Actions\FieldAction;
use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Events\Triggers\ShopEventTrigger;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\ShopState;
use RuntimeException;

/**
 * EnterShopAction class. This class is used to enter a shop.
 *
 * @package Ichiloto\Engine\Entities\Actions
 */
class EnterShopAction extends FieldAction
{
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

    foreach ($this->trigger->dialogue ?? [] as $dialogue) {
      $dialogue->show();
    }
    $scene->setState($shopState);
  }
}