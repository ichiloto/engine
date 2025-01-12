<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines;

/**
 * Represents the turn execution context.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines
 */
readonly class TurnExecutionContext
{
  /**
   * TurnExecutionContext constructor.
   *
   * @param TurnBasedEngine $engine The turn-based engine.
   * @param TurnBasedBattleConfig $battleConfig The battle configuration.
   */
  public function __construct(
    public TurnBasedEngine $engine,
    public TurnBasedBattleConfig $battleConfig,
    public array $args = []
  )
  {
  }
}