<?php

namespace Ichiloto\Engine\Battle\Engines;

use Ichiloto\Engine\Battle\Interfaces\BattleEngineContextInterface;
use Ichiloto\Engine\Battle\UI\BattleScreen as UI;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;

/**
 * Class BattleEngineContext. Represents the battle engine context.
 *
 * @package Ichiloto\Engine\Battle\Engines
 */
class BattleEngineContext implements BattleEngineContextInterface
{
  /**
   * BattleEngineContext constructor.
   *
   * @param Game $game The game.
   * @param Party $party The party.
   * @param Troop $troop The troop.
   * @param UI $ui The UI.
   */
  public function __construct(
    protected(set) Game $game,
    protected(set) Party $party,
    protected(set) Troop $troop,
    protected(set) UI $ui
  )
  {
  }
}