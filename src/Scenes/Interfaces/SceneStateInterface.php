<?php

namespace Ichiloto\Engine\Scenes\Interfaces;

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
   * @return void
   */
  public function execute(): void;

  /**
   * Exits the scene state.
   *
   * @return void
   */
  public function exit(): void;

  /**
   * Returns the context of the scene state.
   *
   * @return SceneStateContextInterface The context of the scene state.
   */
  public function getContext(): SceneStateContextInterface;

  /**
   * Sets the state of the scene.
   *
   * @param SceneStateInterface $state The new state of the scene.
   * @return void
   */
  public function setState(SceneStateInterface $state): void;
}