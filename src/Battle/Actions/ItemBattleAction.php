<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;
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
   */
  public function __construct(
    protected(set) Item $item,
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
      $this->item->quantity--;
    }
  }
}
