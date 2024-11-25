<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Scenes\SceneStateContext;

/**
 * OverworldState. Controls the player’s navigation on the overworld map, usually a more zoomed-out perspective of the game's entire world.
 *
 * Features:
 * - Fast travel between locations.
 * - Random encounter management.
 * - Displaying world landmarks and other points of interest.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class OverworldState extends GameSceneState
{
  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    // TODO: Implement execute() method.
  }
}