<?php

namespace Ichiloto\Engine\Scenes\Battle;

use Ichiloto\Engine\Battle\Interfaces\BattleEngineInterface;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;

class BattleLoader
{
  /**
   * @var BattleLoader|null The battle loader instance.
   */
  protected static ?BattleLoader $instance = null;

  /**
   * The battle loader constructor.
   *
   * @param Game $game The game instance.
   */
  private function __construct(protected Game $game)
  {
  }

  /**
   * Gets the instance of the battle loader.
   *
   * @param Game $game The game instance.
   * @return BattleLoader The battle loader instance.
   */
  public static function getInstance(Game $game): self
  {
    if (!self::$instance) {
      self::$instance = new self($game);
    }

    return self::$instance;
  }

  /**
   * Creates a new battle configuration.
   *
   * @param Party $party The player's party.
   * @param Troop $troop The enemy troop.
   * @param array $battleEvents The battle events.
   * @return BattleConfig The battle configuration.
   */
  public function newConfig(
    Party $party,
    Troop $troop,
    array $battleEvents
  ): BattleConfig
  {
    $events = [];
    foreach ($battleEvents as $event) {
      // TODO: Check if battle event page
      $events[] = $event;
    }
    return new BattleConfig($party, $troop, $events);
  }
}