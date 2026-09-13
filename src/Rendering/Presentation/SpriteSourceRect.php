<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use InvalidArgumentException;

/** Image pixels, independent of the sprite's destination size and grid position. */
final readonly class SpriteSourceRect
{
  public function __construct(public int $x, public int $y, public int $width, public int $height)
  {
    if ($x < 0 || $y < 0 || $width < 1 || $height < 1
      || $width > 4294967295 || $height > 4294967295
      || $x > 4294967295 - $width || $y > 4294967295 - $height) {
      throw new InvalidArgumentException('Sprite source rectangle requires nonnegative origins and positive extents within unsigned 32-bit image coordinates.');
    }
  }

  /** @return array{x: int, y: int, width: int, height: int} */
  public function toArray(): array
  {
    return ['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
  }
}
