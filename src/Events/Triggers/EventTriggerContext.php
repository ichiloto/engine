<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * The EventTriggerContext class.
 *
 * @package Ichiloto\Engine\Events\Triggers
 */
readonly class EventTriggerContext implements EventTriggerContextInterface
{
  /**
   * The EventTriggerContext constructor.
   *
   * @param EventInterface $event The event.
   * @param Vector2 $coordinates The coordinates of the event.
   * @param Player $player The player.
   * @param GameScene $scene The scene.
   * @param MapManager $mapManager The map manager.
   */
  public function __construct(
    public EventInterface $event,
    public Vector2 $coordinates,
    public Player $player,
    public GameScene $scene,
    public MapManager $mapManager
  )
  {
  }
}