<?php

namespace Ichiloto\Engine\Events\Interfaces;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * The EventTriggerContextInterface interface represents the context of the event trigger.
 *
 * @package Ichiloto\Engine\Events\Interfaces
 */
interface EventTriggerContextInterface
{
  /**
   * @var EventInterface $event The event.
   */
  public EventInterface $event {
    get;
  }

  /**
   * @var Vector2 $coordinates The coordinates of the event.
   */
  public Vector2 $coordinates {
    get;
  }

  /**
   * @var Player $player The player.
   */
  public Player $player {
    get;
  }

  /**
   * @var GameScene $scene The scene.
   */
  public GameScene $scene {
    get;
  }

  /**
   * @var MapManager $mapManager The map manager.
   */
  public MapManager $mapManager {
    get;
  }
}