<?php

namespace Ichiloto\Engine\Core\Interfaces;

use Ichiloto\Engine\Core\PlayActivity;

/**
 * A scene that can say what the player is doing in it. Scenes that do not
 * implement this count as paused.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface PlayActivitySourceInterface
{
  /** What the player is doing in this scene now. */
  public function getPlayActivity(): PlayActivity;
}
