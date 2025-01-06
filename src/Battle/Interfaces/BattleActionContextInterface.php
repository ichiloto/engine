<?php

namespace Ichiloto\Engine\Battle\Interfaces;

use Ichiloto\Engine\Battle\UI\BattleMessageWindow;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Battler;
use Ichiloto\Engine\Scenes\Battle\BattleScene;

/**
 * Represents the battle action context interface.
 *
 * @package Ichiloto\Engine\Battle\Interfaces
 */
interface BattleActionContextInterface
{
  public Battler $battler {
    get;
  }

  public array $targets {
    get;
  }

  public BattleScene $scene {
    get;
  }

  public BattleMessageWindow $messageWindow {
    get;
  }
}