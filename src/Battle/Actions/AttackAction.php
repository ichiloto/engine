<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;

class AttackAction extends BattleAction
{
  /**
   * @inheritDoc
   */
  public function execute(Actor $actor, array $targets): void
  {
    // TODO: Implement execute() method.
    foreach ($targets as $target) {

    }
  }
}