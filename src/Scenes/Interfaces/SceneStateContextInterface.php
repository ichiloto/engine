<?php

namespace Ichiloto\Engine\Scenes\Interfaces;

use Ichiloto\Engine\Scenes\SceneManager;

/**
 * Interface SceneStateContextInterface
 *
 * @package Ichiloto\Engine\Scenes\Interfaces
 */
interface SceneStateContextInterface
{
  /**
   * Returns the previous context.
   *
   * @return SceneStateContextInterface|null The previous context.
   */
  public function getPreviousContext(): ?SceneStateContextInterface;

  /**
   * Returns the current scene.
   *
   * @return SceneInterface The current scene.
   */
  public function getScene(): SceneInterface;

  /**
   * Returns the scene manager.
   *
   * @return SceneManager The scene manager.
   */
  public function getSceneManager(): SceneManager;
}