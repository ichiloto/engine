<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Inventory\Items\Item;

/**
 * Executes an inventory item as a battle action.
 *
 * @package Ichiloto\Engine\Battle\Actions
 */
class ItemBattleAction extends BattleAction
{
  /**
   * @param Item $item The inventory item represented by this action.
   * @param Inventory|null $inventory The inventory the item is drawn from.
   *   When provided, consumption goes through it so depleted stacks are
   *   removed; without one the item quantity is decremented on its own.
   */
  public function __construct(
    protected(set) Item $item,
    protected(set) ?Inventory $inventory = null,
  )
  {
    parent::__construct($item->name);
  }

  /**
   * @inheritDoc
   */
  public function execute(Actor $actor, array $targets): void
  {
    if ($actor->isKnockedOut || $this->item->quantity < 1) {
      return;
    }

    $didApply = false;

    foreach ($targets as $target) {
      if (! $target instanceof Actor) {
        continue;
      }

      foreach ($this->item->effects as $effect) {
        $effect->apply($target);
        $didApply = true;
      }
    }

    if ($didApply && $this->item->consumable) {
      $this->consumeOne();
    }
  }

  /**
   * Consumes a single unit of the item.
   *
   * Consuming through the inventory keeps stack bookkeeping in one place: it
   * decrements the quantity and drops the stack once it is depleted, so a
   * fully used item cannot linger as a phantom zero-quantity entry in the
   * field menus.
   *
   * @return void
   */
  protected function consumeOne(): void
  {
    if ($this->inventory?->consumeQuantity($this->item->name, 1)) {
      return;
    }

    $this->item->quantity--;
  }
}
