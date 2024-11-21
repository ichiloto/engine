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
  /**
   * The state of the scene.
   *
   * @var GameSceneState|null
   */
  protected ?GameSceneState $state = null;
  /**
   * The configuration of the game.
   *
   * @var GameConfig|null
   */
  protected ?GameConfig $config = null;

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

  /**
   * Configures the game scene.
   *
   * @param GameConfig $config The game configuration.
   * @return void
   */
  public function configure(GameConfig $config): void
  {
    $this->config = $config;
  }
}