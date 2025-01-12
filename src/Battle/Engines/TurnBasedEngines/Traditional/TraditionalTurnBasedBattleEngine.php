<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional;

use Exception;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\EnemyActionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\PlayerActionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnInitState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnResolutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedEngine;
use Ichiloto\Engine\Battle\Interfaces\BattleEngineContextInterface;
use Ichiloto\Engine\IO\Input;

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
  public function start(): void
  {
    $this->positionBattlers();
    $this->turnStateExecutionContext = new TurnStateExecutionContext(
      $this->game,
      $this->battleConfig->party,
      $this->battleConfig->troop,
      $this->battleConfig->ui,
      []
    );
    $this->setState($this->turnInitState);
  }

  /**
   * @inheritDoc
   * @throws Exception
   */
  public function run(BattleEngineContextInterface $context): void
  {
    if (Input::isButtonDown("quit")) {
      $this->game->quit();
    }

    $this->state->update($this->turnStateExecutionContext);
  }
}