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
    bool $allowOutsideCanvas = false,
  )
  {
    foreach ([$x, $y, $width, $height] as $value) {
      if (!is_finite($value)) {
        throw new InvalidArgumentException('Canvas rectangle values must be finite.');
      }
    }
    if ($width <= 0 || $height <= 0 || max(abs($x), abs($y), $width, $height) > CanvasValidation::MAX_EXTENT) {
      throw new InvalidArgumentException('Canvas rectangles require bounded origins and positive bounded extents.');
    }
    if (!$allowOutsideCanvas) { $this->assertWithin(CanvasValidation::MAX_EXTENT, CanvasValidation::MAX_EXTENT); }
  }

  public function assertWithin(int $width, int $height): void
  {
    if ($this->x < 0 || $this->y < 0 || !is_finite($this->x + $this->width) || !is_finite($this->y + $this->height)
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
