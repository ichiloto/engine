<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines;

use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;

/**
 * Class TurnBasedBattleConfig. Represents the turn-based battle configuration.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines
 */
class TurnBasedBattleConfig extends BattleConfig
{
  /**
   * Creates a new instance of the turn-based battle configuration.
   *
   * @param Party $party The party.
   * @param Troop $troop The troop.
   * @param BattleScreen $ui The battle screen.
   * @param array $events The events.
   */
  public function __construct(
    Party $party,
    Troop $troop,
    protected(set) BattleScreen $ui,
    array $events = []
  )
  {
    parent::__construct($party, $troop, $events);
  }
}