<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Battle\Enumerations\BattleEngineType;
use Ichiloto\Engine\Battle\Interfaces\BattleEngineInterface;
use Ichiloto\Engine\Core\Game;

/**
 * Creates battle engine instances from project settings.
 *
 * @package Ichiloto\Engine\Battle
 */
final class BattleEngineFactory
{
  /**
   * BattleEngineFactory constructor.
   */
  private function __construct()
  {
  }

  /**
   * Creates the requested battle engine.
   *
   * @param Game $game The game instance.
   * @param BattleEngineType $type The desired engine type.
   * @return BattleEngineInterface
   */
  public static function create(Game $game, BattleEngineType $type): BattleEngineInterface
  {
    return match ($type) {
      BattleEngineType::ACTIVE_TIME => new ActiveTimeBattleEngine($game),
      default => new TraditionalTurnBasedBattleEngine($game),
    };
  }
}
