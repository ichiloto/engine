<?php

namespace Ichiloto\Engine\Scenes\Interfaces;

use Ichiloto\Engine\Core\GameObject;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;

/**
 * Interface SceneInterface
 *
 * @package Ichiloto\Engine\Scenes\Interfaces
 */
interface SceneInterface extends CanStart, CanResume, CanUpdate, CanRender
{
  /**
   * Gets the root game objects.
   *
   * @return GameObject[] The root game objects.
   */
  public function getRootGameObjects(): array;
}