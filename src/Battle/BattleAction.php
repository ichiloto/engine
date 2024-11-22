<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Interfaces\BattleActionInterface;
use Ichiloto\Engine\Entities\Character as Actor;

/**
 * Class BattleAction. Represents an action that can be executed in a battle.
 *
 * @package Ichiloto\Engine\Battle
 */
class BattleAction implements BattleActionInterface
{

  /**
   * @inheritDoc
   */
  public function execute(Actor $actor, array $targets): void
  {
    // TODO: Implement execute() method.
  }
}