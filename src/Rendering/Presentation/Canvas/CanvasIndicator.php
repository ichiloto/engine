<?php

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

final readonly class CanvasIndicator
{
  /** Root-qualified group IDs followed by the existing image/composite ID. Null retains local association.
   * @var ?list<string>
   */
  public ?array $imagePath;
  public function __construct(
    public string $id,
    public string $imageId,
    public CanvasIndicatorKind $kind,
    public CanvasRectangle $bounds,
    public int $strokeWidth,
    public PresentationColor $color,
    public int $layer = 0,
    ?array $imagePath = null,
  )
  {
    CanvasValidation::id($id);
    CanvasValidation::id($imageId);
    $path = null;
    if ($imagePath !== null) {
      if (!array_is_list($imagePath) || count($imagePath) < 2 || count($imagePath) > CanvasGroup::MAX_DEPTH + 1) {
        throw new InvalidArgumentException('Indicator imagePath requires 1..8 group IDs and an image/composite leaf.');
      }
      $path = [];
      foreach ($imagePath as $part) {
        if (!is_string($part)) { throw new InvalidArgumentException('Indicator imagePath requires stable string IDs.'); }
        CanvasValidation::id($part); $path[] = $part;
      }
      if ($path[array_key_last($path)] !== $imageId) {
        throw new InvalidArgumentException('Indicator imagePath leaf must equal imageId.');
      }
    }
    $this->imagePath = $path;
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
      'color' => $this->color->toArray(), 'layer' => $this->layer,
      ...($this->imagePath === null ? [] : ['imagePath' => $this->imagePath])];
  }
}
