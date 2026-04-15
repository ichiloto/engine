<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;

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
   * @param \Ichiloto\Engine\Scenes\Battle\BattleConfig $battleConfig The battle configuration.
   */
  public function __construct(
    public TurnBasedEngine $engine,
    public BattleConfig $battleConfig,
    public array $args = []
  )
  {
  }
}