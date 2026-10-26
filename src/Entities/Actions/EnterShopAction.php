<?php

namespace Ichiloto\Engine\Entities\Actions;

use Exception;
use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Events\Triggers\ShopEventTrigger;

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
    foreach ($this->trigger->dialogue ?? [] as $dialogue) {
      $dialogue->show();
    }

    $this->trigger->offer->open($context->scene);
  }
}
