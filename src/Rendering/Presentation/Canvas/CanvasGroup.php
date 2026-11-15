<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;

/** A local drawing transformed at paint time; child source geometry and preparation stay intact. */
final readonly class CanvasGroup
{
  public const int MAX_INSTANCES = 128;
  public const int MAX_DEPTH = 8;

  public function __construct(
    public string $id,
    public float $x,
    public float $y,
    public PresentationCanvas $canvas,
    public int $layer = 0,
    public ?CanvasRectangle $clipRect = null,
    public ?CanvasColorTransform $colorTransform = null,
    public ?CanvasGroupScale $scale = null,
  ) {
    CanvasValidation::id($id);
    SpriteValidation::validateSigned32BitRange($layer);
    new CanvasRectangle($x, $y, $canvas->width, $canvas->height, allowOutsideCanvas: true);
    $this->projectRectangle(new CanvasRectangle(0, 0, $canvas->width, $canvas->height));
    if ($canvas->viewportFit !== CanvasViewportFit::NATIVE) {
      throw new InvalidArgumentException('Canvas groups inherit their parent viewport fit.');
    }
  }

  /** Keep fractional geometry until the final renderer rasterization. */
  public function projectRectangle(CanvasRectangle $rectangle): CanvasRectangle
  {
    $factor = $this->scale?->factor ?? 1.0;
    $originX = ($this->scale?->origin->x ?? 0.0) * $this->canvas->width;
    $originY = ($this->scale?->origin->y ?? 0.0) * $this->canvas->height;
    return new CanvasRectangle($this->x + $originX + $factor * ($rectangle->x - $originX),
      $this->y + $originY + $factor * ($rectangle->y - $originY),
      $factor * $rectangle->width, $factor * $rectangle->height, allowOutsideCanvas: true);
  }

  /** Project safe areas and intersect them with this group and its parent surface. */
  public function getOverlayProtection(int $width, int $height): array
  {
    $clip = $this->clipRect ?? new CanvasRectangle(0, 0, $width, $height);
    $areas = [];
    foreach ($this->canvas->getOverlayProtection() as $area) {
      $projected = $this->projectRectangle($area);
      $left = max(0, $clip->x, $projected->x);
      $top = max(0, $clip->y, $projected->y);
      $right = min($width, $clip->x + $clip->width, $projected->x + $projected->width);
      $bottom = min($height, $clip->y + $clip->height, $projected->y + $projected->height);
      if ($left < $right && $top < $bottom) {
        $areas[] = new CanvasRectangle($left, $top, $right - $left, $bottom - $top);
      }
    }
    return $areas;
  }

  /** Wrap a complete root without changing any qualified association inside it. */
  public static function wrapRoot(string $id, float $x, float $y, PresentationCanvas $canvas, int $layer = 0,
    ?CanvasRectangle $clipRect = null, ?CanvasColorTransform $colorTransform = null, ?CanvasGroupScale $scale = null): self
  {
    return new self($id, $x, $y, CanvasIndicatorReferences::nestRootPaths($canvas, $id), $layer, $clipRect, $colorTransform, $scale);
  }

  public function toArray(): array
  {
    return ['id' => $this->id, 'origin' => ['x' => $this->x, 'y' => $this->y], 'layer' => $this->layer,
      'canvas' => $this->canvas->toArray(),
      ...($this->clipRect === null ? [] : ['clipRect' => $this->clipRect->toArray()]),
      ...($this->colorTransform === null ? [] : ['colorTransform' => $this->colorTransform->toArray()]),
      ...($this->scale === null || $this->scale->factor === 1.0 ? [] : ['scale' => $this->scale->toArray()])];
  }
}
