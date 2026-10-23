<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

/** A rectangle copied from a sheet into a tile, in pixels; never scaled. */
final readonly class TilePiece
{
  /**
   * @param string $sheet The sheet it is copied from: an RPG Maker sheet's name ({@see TilesetSheet}), or a corner
   *   sheet's key ({@see CornerSheet::$key}).
   */
  public function __construct(
    public string $sheet,
    public int $x,
    public int $y,
    public int $width,
    public int $height,
    public int $left,
    public int $top,
  ) {}
}
