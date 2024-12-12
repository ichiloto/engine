<?php

namespace Ichiloto\Engine\Entities\Interfaces;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * The ActionContextInterface interface.
 *
 * @package Ichiloto\Engine\Entities\Interfaces
 */
interface ActionContextInterface
{
  /**
   * @var Player $player The player.
   */
  public Player $player {
    get;
  }
  /**
   * @var GameScene $scene The game scene.
   */
  public GameScene $scene {
    get;
  }
  /**
   * @var Vector2 $position The position of the action.
   */
  public Vector2 $position {
    get;
  }
  /**
   * @var Party $party The party.
   */
  public Party $party {
    get;
  }
}