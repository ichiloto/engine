<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

/** RPG Maker MZ's tileset sheets and their layouts, in tiles. */
enum TilesetSheet: string
{
  /** Animated water and waterfalls. */
  case A1 = 'A1';
  /** Ground. */
  case A2 = 'A2';
  /** Building exteriors. */
  case A3 = 'A3';
  /** Walls. */
  case A4 = 'A4';
  /** Normal lower tiles. */
  case A5 = 'A5';
  case B = 'B';
  case C = 'C';
  case D = 'D';
  case E = 'E';

  public function getColumns(): int
  {
    return $this === self::A5 ? 8 : 16;
  }

  public function getRows(): int
  {
    return match ($this) {
      self::A1, self::A2 => 12,
      self::A3 => 8,
      self::A4 => 15,
      default => 16,
    };
  }
}
