<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use Ichiloto\Engine\Shop\ShopOffer;
use Ichiloto\Engine\Shop\ShopVisit;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use RuntimeException;

/**
 * The `shop` script command: opens a shop, and the script continues once
 * the player leaves it.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final class ShopCommand implements ScriptCommandHandlerInterface
{
  /** @inheritDoc */
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    $itemStore = ConfigStore::get(ItemStore::class);

    if (! $itemStore instanceof ItemStore) {
      throw new RuntimeException('shop needs the project item store.');
    }

    $shopState = ShopOffer::fromData($command, $itemStore)->open($context->scene);

    return ScriptCommandOutcome::waitFor(new ShopVisit($context->scene, $shopState));
  }
}
