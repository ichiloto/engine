<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Core\Game;

/**
 * The battle manager.
 *
 * @package Ichiloto\Engine\Battle
 */
class BattleManager
{
  /**
   * The battle manager instance.
   *
   * @var BattleManager
   */
  private static BattleManager $instance;

  /**
   * The battle manager constructor.
   */
  private function __construct(protected Game $game)
  {
  }

  /**
   * Gets the battle manager instance.
   *
   * @return BattleManager Returns the battle manager instance.
   */
  public static function getInstance(Game $game): BattleManager
  {
    if (!isset(self::$instance)) {
      self::$instance = new BattleManager($game);
    }

    return self::$instance;
  }
}