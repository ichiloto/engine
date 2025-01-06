<?php

namespace Ichiloto\Engine\Scenes\Interfaces;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameObject;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\UI\UIManager;

/**
 * Interface SceneInterface
 *
 * @package Ichiloto\Engine\Scenes\Interfaces
 */
interface SceneInterface extends CanStart, CanResume, CanUpdate, CanRender
{
  /**
   * The camera of the scene.
   *
   * @var Camera
   */
  public Camera $camera {
    get;
  }

  /**
   * Gets the game.
   *
   * @return Game The game.
   */
  public function getGame(): Game;

  /**
   * @var string The name of the game.
   */
  public string $name {
    get;
  }

  /**
   * Gets the root game objects.
   *
   * @return GameObject[] The root game objects.
   */
  public function getRootGameObjects(): array;

  /**
   * Returns the UI manager.
   *
   * @return UIManager The UI manager.
   */
  public function getUI(): UIManager;

  /**
   * Returns whether the scene is started.
   *
   * @return bool Whether the scene is started.
   */
  public function isStarted(): bool;

  /**
   * Renders the background tile at the given position.
   *
   * @param int $x The x position.
   * @param int $y The y position.
   * @return void
   */
  public function renderBackgroundTile(int $x, int $y): void;
}