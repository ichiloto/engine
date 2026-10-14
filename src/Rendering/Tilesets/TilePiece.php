<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Tilesets;

/** A rectangle copied from a sheet into a tile, in pixels; never scaled. */
final readonly class TilePiece
{
  public function __construct(
    public TilesetSheet $sheet,
    public int $x,
    public int $y,
    public int $width,
    public int $height,
    public int $left,
    public int $top,
  ) {}
}
