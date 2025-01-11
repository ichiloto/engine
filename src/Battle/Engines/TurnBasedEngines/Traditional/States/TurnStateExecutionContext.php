<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;

/**
 * Class TurnStateExecutionContext. Represents the context of a turn state.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States
 */
class TurnStateExecutionContext
{
  /**
   * TurnStateExecutionContext constructor.
   *
   * @param Game $game The game.
   * @param Party $party The party.
   * @param Troop $troop The troop.
   * @param BattleScreen $ui The battle screen.
   * @param array $args The arguments.
   */
  public function __construct(
    protected(set) Game $game,
    protected(set) Party $party,
    protected(set) Troop $troop,
    protected(set) BattleScreen $ui,
    protected(set) array $args
  )
  {
  }
}