<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\ItemScope;

/**
 * A turn spent doing nothing (a failed escape, hesitation).
 *
 * @package Ichiloto\Engine\Battle\Actions
 */
class PassAction extends BattleAction
{
  public ItemScope $targetScope {
    get { return new ItemScope(ItemScopeSide::USER); }
  }
  /**
   * @inheritDoc
   */
  public function execute(Actor $actor, array $targets): void
  {
    // Deliberately nothing.
  }
}
