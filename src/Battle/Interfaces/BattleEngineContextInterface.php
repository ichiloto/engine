<?php

namespace Ichiloto\Engine\Battle\Interfaces;

use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;

interface BattleEngineContextInterface
{
  public Game $game {
    get;
  }

  public Party $party {
    get;
  }

  public Troop $troop {
    get;
  }

  public BattleScreen $ui {
    get;
  }
}