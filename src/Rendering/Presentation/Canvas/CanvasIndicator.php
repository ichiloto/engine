<?php

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

final readonly class CanvasIndicator
{
  public function __construct(
    public string $id,
    public string $imageId,
    public CanvasIndicatorKind $kind,
    public CanvasRectangle $bounds,
    public int $strokeWidth,
    public PresentationColor $color,
    public int $layer = 0,
  )
  {
    CanvasValidation::id($id);
    CanvasValidation::id($imageId);
    SpriteValidation::validateSigned32BitRange($layer);
    if ($strokeWidth < 1 || $strokeWidth > 16 || $strokeWidth > min($bounds->width, $bounds->height)) {
      throw new InvalidArgumentException('Canvas indicator stroke must be in 1..16 and fit inside its bounds.');
    }
  }

  /** @return array<string, mixed> */
  public function toArray(): array
  {
    return ['id' => $this->id, 'imageId' => $this->imageId, 'kind' => $this->kind->value,
      'bounds' => $this->bounds->toArray(), 'strokeWidth' => $this->strokeWidth,
      'color' => $this->color->toArray(), 'layer' => $this->layer];
  }
}
