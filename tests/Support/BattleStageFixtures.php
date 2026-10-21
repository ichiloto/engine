<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;

/**
 * A stage place whose feet stand at a canvas height on a ground whose near
 * edge is at $groundBottom (600, the battle UI's top on a 1350x720 canvas with
 * the default 10x20 grid and no gap), so a synthetic formation keeps its
 * canvas geometry while it is authored the way battles read it.
 */
function getStageSlot(float $x, float $footY, float $width, float $height, float $displayScale = 1.0,
  float $groundBottom = 600): BattleStageSlot
{
  return new BattleStageSlot($x, $groundBottom - $footY, $width, $height, $displayScale);
}
