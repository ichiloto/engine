<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Core\Timers;
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

  /**
   * Hands control to a state, when the engine has one to hand it to.
   *
   * The active-time engine has no separate player and enemy phases: its flow
   * state drives every battler as their gauge fills, so the states the
   * traditional turn order hands off to are null there. Handing off is then
   * simply not something to do, and control returns to the flow state.
   *
   * @param TurnState|null $state The state to hand off to.
   * @return bool True when the handoff was made.
   */
  protected function setStateIfPresent(?TurnState $state): bool
  {
    if ($state === null) {
      return false;
    }

    $this->setState($state);

    return true;
  }

  /**
   * Waits for the given number of seconds.
   *
   * @param float $seconds The time to wait in seconds.
   * @return void
   */
  protected function pause(float $seconds): void
  {
    Timers::wait(max(0, $seconds));
  }
}