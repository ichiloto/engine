<?php

namespace Ichiloto\Engine\Scenes\Interfaces;

use Ichiloto\Engine\Scenes\SceneStateContext;

/**
 * Interface SceneStateInterface
 *
 * @package Ichiloto\Engine\Scenes\Interfaces
 */
interface SceneStateInterface
{
  /**
   * Enters the scene state.
   *
   * @return void
   */
  public function enter(): void;

  /**
   * Executes the scene state.
   *
   * @param SceneStateContext|null $context The context of the scene state.
   * @return void
   */
  public function execute(?SceneStateContext $context = null): void;

  /**
   * Exits the scene state.
   *
   * @return void
   */
  public function exit(): void;

  public \Ichiloto\Engine\Scenes\Interfaces\SceneStateContextInterface $context {
    get;
  }

  /**
   * Sets the state of the scene.
   *
   * @param SceneStateInterface $state The new state of the scene.
   * @return void
   */
  public function setState(SceneStateInterface $state): void;
}