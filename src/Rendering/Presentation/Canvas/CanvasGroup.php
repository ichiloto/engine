<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

/** One rigidly translated local drawing; parent placement never changes child geometry. */
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
  ) {
    CanvasValidation::id($id);
    new CanvasRectangle($x, $y, $canvas->width, $canvas->height, allowOutsideCanvas: true);
    if ($canvas->viewportFit !== CanvasViewportFit::NATIVE) {
      throw new InvalidArgumentException('Canvas groups inherit their parent viewport fit.');
    }
  }

  /** Translate safe areas and intersect them with this group and its parent surface. */
  public function getOverlayProtection(int $width, int $height): array
  {
    $clip = $this->clipRect ?? new CanvasRectangle(0, 0, $width, $height);
    $areas = [];
    foreach ($this->canvas->getOverlayProtection() as $area) {
      $left = max(0, $clip->x, $this->x + $area->x);
      $top = max(0, $clip->y, $this->y + $area->y);
      $right = min($width, $clip->x + $clip->width, $this->x + $area->x + $area->width);
      $bottom = min($height, $clip->y + $clip->height, $this->y + $area->y + $area->height);
      if ($left < $right && $top < $bottom) {
        $areas[] = new CanvasRectangle($left, $top, $right - $left, $bottom - $top);
      }
    }
    return $areas;
  }

  public function toArray(): array
  {
    return ['id' => $this->id, 'origin' => ['x' => $this->x, 'y' => $this->y], 'layer' => $this->layer,
      'canvas' => $this->canvas->toArray(),
      ...($this->clipRect === null ? [] : ['clipRect' => $this->clipRect->toArray()])];
  }
}
