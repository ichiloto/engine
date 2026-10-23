<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

/**
 * How a party on foot crosses a terrain kind. Vehicles are not authored yet:
 * when they are, a kind gains a separate per-vehicle passage keyed by vehicle
 * id, and this stays the passage on foot.
 */
enum TerrainPassage: string
{
  case WALK = 'walk';
  case SOLID = 'solid';
}
