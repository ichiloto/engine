<?php

namespace Ichiloto\Engine\Shop;

use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\ShopState;

/**
 * A script waiting while the player is in a shop it opened.
 *
 * Scripts only advance in the field, so the visit is over the first time
 * the script is updated with the shop no longer showing.
 *
 * @package Ichiloto\Engine\Shop
 */
final readonly class ShopVisit implements EventPendingOperationInterface
{
  public function __construct(
    private GameScene $scene,
    private ShopState $shopState,
  )
  {
  }

  /** @inheritDoc */
  public function update(float $deltaSeconds): bool
  {
    return $this->scene->state !== $this->shopState;
  }

  /**
   * The shop stays the player's to leave. An abandoned script has nothing
   * left to resume when they do.
   */
  public function cancel(): void
  {
  }
}
