<?php

namespace Ichiloto\Engine\Scenes\Game;

use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\States\GameSceneState;

/**
 * Class GameScene. Represents the game scene.
 *
 * @package Ichiloto\Engine\Scenes\Game
 */
class GameScene extends AbstractScene
{
  protected ?GameSceneState $state = null;

  /**
   * Sets the state of the scene.
   *
   * @param GameSceneState $state The state.
   * @return void
   */
  public function setState(GameSceneState $state): void
  {
    $this->state?->exit();
    $this->state = $state;
    $this->state?->enter();
  }
}