<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

/**
 * What a tile shows the player at ground level: open ground, water, a
 * barrier such as a cliff face, wall or rock, or art drawn over the player
 * that says nothing about the ground beneath it. Only validation reads it,
 * to compare what a map shows with what it lets the player do; passage
 * always comes from the map's own collision, never from a tile.
 */
enum TileAppearance: string
{
  case GROUND = 'ground';
  case WATER = 'water';
  case BARRIER = 'barrier';
  case OVERHEAD = 'overhead';

  /** Whether the tile shows something the player cannot walk on. */
  public function showsObstacle(): bool
  {
    return $this === self::WATER || $this === self::BARRIER;
  }
}
