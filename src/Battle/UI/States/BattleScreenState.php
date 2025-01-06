<?php

namespace Ichiloto\Engine\Battle\UI\States;

use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Scenes\Battle\States\BattleSceneState;

abstract class BattleScreenState
{
  public function __construct(
    protected BattleScreen $battleScreen
  )
  {
  }

  public function setState(BattleSceneState $state): void
  {
    $this->battleScreen->setState($state);
  }
}