<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedEngine;
use Ichiloto\Engine\Util\Debug;

/**
 * Represents a turn state. Each turn state represents a phase in the turn-based battle system.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States
 */
abstract class TurnState
{
  public function __construct(protected TurnBasedEngine $engine)
  {
  }

  /**
   * Initializes the state.
   *
   * @param TurnStateExecutionContext $context The context.
   * @return void
   */
  public function enter(TurnStateExecutionContext $context): void
  {
    // Do nothing. This method is meant to be overridden.
  }

  /**
   * Updates the turn state.
   *
   * @param TurnStateExecutionContext $context The context.
   * @return void
   */
  public abstract function update(TurnStateExecutionContext $context): void;

  /**
   * Exits the state.
   *
   * @param TurnStateExecutionContext $context The context.
   * @return void
   */
  public function exit(TurnStateExecutionContext $context): void
  {
    // Do nothing. This method is meant to be overridden.
  }

  protected function setState(TurnState $state): void
  {
    $this->engine->setState($state);
  }
}