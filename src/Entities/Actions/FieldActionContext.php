<?php

namespace Ichiloto\Engine\Entities\Actions;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * The FieldActionContext class.
 *
 * @package Ichiloto\Engine\Entities\Actions
 */
class FieldActionContext implements ActionContextInterface
{
  /**
   * @inheritdoc
   */
  public Party $party {
    get {
      return $this->scene->party;
    }
  }
  /**
   * FieldActionContext constructor.
   *
   * @param Player $player The player.
   * @param GameScene $scene The scene.
   * @param Vector2 $position The position.
   */
  public function __construct(
    protected(set) Player $player,
    protected(set) GameScene $scene,
    protected(set) Vector2 $position,
  )
  {
  }
}