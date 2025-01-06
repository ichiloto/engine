<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional;

use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedEngine;

/**
 * Represents a traditional turn-based battle engine.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional
 */
class TraditionalTurnBasedBattleEngine extends TurnBasedEngine
{
  /**
   * @inheritDoc
   */
  public function run(): void
  {
    // TODO: Implement run() method.
    if (\Ichiloto\Engine\IO\Input::isButtonDown("quit")) {
      $this->game->quit();
    }
  }
}