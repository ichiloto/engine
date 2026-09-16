<?php

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

/** Resolved graphical units, independent of terminal cells and image pixels. */
final readonly class CanvasRectangle
{
  public function __construct(
    public float $x,
    public float $y,
    public float $width,
    public float $height,
  )
  {
    foreach ([$x, $y, $width, $height] as $value) {
      if (!is_finite($value)) {
        throw new InvalidArgumentException('Canvas rectangle values must be finite.');
      }
    }
    if ($x < 0 || $y < 0 || $width <= 0 || $height <= 0) {
      throw new InvalidArgumentException('Canvas rectangles require nonnegative origins and positive extents.');
    }
    $this->assertWithin(CanvasValidation::MAX_EXTENT, CanvasValidation::MAX_EXTENT);
  }

  public function assertWithin(int $width, int $height): void
  {
    if (!is_finite($this->x + $this->width) || !is_finite($this->y + $this->height)
      || $this->x + $this->width > $width || $this->y + $this->height > $height) {
      throw new InvalidArgumentException('Canvas rectangle must fit completely within the canvas.');
    }
  }

  /** @return array{x: float, y: float, width: float, height: float} */
  public function toArray(): array
  {
    return ['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
  }
}
