<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\Text\TextBounds;
use Ichiloto\Engine\Rendering\Presentation\Text\TextMeasurement;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** A live project layout placed in local canvas pixels, never persisted font identity. */
final readonly class CanvasLabel
{
  public TextBounds $paintBounds;

  public function __construct(
    public string $id,
    public TextMeasurement $measurement,
    public float $x,
    public float $y,
    public int $layer = 0,
    public ?CanvasRectangle $clipRect = null,
    public float $opacity = 1,
  ) {
    CanvasValidation::id($id);
    SpriteValidation::validateSigned32BitRange($layer);
    if (!is_finite($x) || !is_finite($y) || !is_finite($opacity) || $opacity < 0 || $opacity > 1) {
      throw new InvalidArgumentException('Canvas label origins must be finite and opacity must be in 0..1.');
    }
    $paint = $measurement->metrics->paintBounds;
    $this->paintBounds = new TextBounds($x + $paint->x, $y + $paint->y, $paint->width, $paint->height);
    $this->assertWithin(CanvasValidation::MAX_EXTENT, CanvasValidation::MAX_EXTENT);
  }

  public function assertWithin(int $width, int $height): void
  {
    $paint = $this->paintBounds;
    if ($paint->x < 0 || $paint->y < 0 || !is_finite($paint->x + $paint->width)
      || !is_finite($paint->y + $paint->height) || $paint->x + $paint->width > $width
      || $paint->y + $paint->height > $height) {
      throw new InvalidArgumentException('Canvas label paint bounds must fit completely within the canvas, regardless of clipping.');
    }
    $this->clipRect?->assertWithin($width, $height);
  }

  /** @return array<string, mixed> */
  public function toArray(): array
  {
    return ['id' => $this->id, 'layoutId' => $this->measurement->layoutId,
      'origin' => ['x' => $this->x, 'y' => $this->y], 'layer' => $this->layer,
      ...($this->clipRect === null ? [] : ['clipRect' => $this->clipRect->toArray()]),
      ...($this->opacity === 1.0 ? [] : ['opacity' => $this->opacity])];
  }
}
