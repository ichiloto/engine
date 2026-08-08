<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;

/**
 * A turn spent doing nothing (a failed escape, hesitation).
 *
 * @package Ichiloto\Engine\Battle\Actions
 */
class PassAction extends BattleAction
{
  /**
   * @inheritDoc
   */
  public function execute(Actor $actor, array $targets): void
  {
    // Deliberately nothing.
  }
}
