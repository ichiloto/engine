<?php

namespace Ichiloto\Engine\Battle\Interfaces;

use Ichiloto\Engine\Entities\Character as Actor;

/**
 * BattleActionInterface is an interface implemented by all classes that can be used as battle actions.
 *
 * @package Ichiloto\Engine\Battle\Interfaces
 */
interface BattleActionInterface
{
  /**
   * Executes the battle action.
   *
   * @param Actor $actor The actor that executes the action.
   * @param array $targets The targets of the action.
   */
  public function execute(Actor $actor, array $targets): void;
}