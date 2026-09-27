<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

/**
 * The part of a tile one field cell shows. A field cell is half a tile wide
 * (one column of RPG Maker's quarter tiles), so a cell shows a whole tile
 * centred on it, the tile's left or right half, or, for an autotile one cell
 * wide, the outer halves of both quarter columns so both edges show.
 */
enum TileSlice: string
{
  case WHOLE = 'whole';
  case LEFT = 'left';
  case RIGHT = 'right';
  case NARROW = 'narrow';

  /** The slice's width in source pixels. */
  public function getWidth(int $tileSize): int
  {
    return $this === self::WHOLE ? $tileSize : intdiv($tileSize, 2);
  }

  /** Source pixels from the cell's left edge to the slice's: a whole tile is centred on its half-width cell. */
  public function getLeft(int $tileSize): int
  {
    return $this === self::WHOLE ? -intdiv($tileSize, 4) : 0;
  }

  /**
   * The pieces of a composed tile that fall in this slice, placed within it.
   *
   * @param list<TilePiece> $pieces
   * @return list<TilePiece>
   */
  public function cut(array $pieces, int $tileSize): array
  {
    $half = intdiv($tileSize, 2);
    $quarter = intdiv($tileSize, 4);
    // Destination ranges across the tile, each with the shift into the slice.
    $ranges = match ($this) {
      self::WHOLE => [[0, $tileSize, 0]],
      self::LEFT => [[0, $half, 0]],
      self::RIGHT => [[$half, $tileSize, $half]],
      self::NARROW => [[0, $quarter, 0], [$tileSize - $quarter, $tileSize, $half]],
    };
    $cut = [];
    foreach ($ranges as [$from, $to, $shift]) {
      foreach ($pieces as $piece) {
        $left = max($piece->left, $from);
        $right = min($piece->left + $piece->width, $to);
        if ($right > $left) {
          $cut[] = new TilePiece($piece->sheet, $piece->x + $left - $piece->left, $piece->y, $right - $left,
            $piece->height, $left - $shift, $piece->top);
        }
      }
    }
    return $cut;
  }
}
