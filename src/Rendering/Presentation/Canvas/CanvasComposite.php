<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** A bounded offscreen raster; ordered operations blend only against this owned surface. */
final readonly class CanvasComposite
{
  public array $operations;

  /** @param list<CanvasCompositeOperation> $operations */
  public function __construct(public string $id, public int $width, public int $height,
    public CanvasRectangle $destination, array $operations, public int $layer = 0,
    public float $opacity = 1, public ?CanvasRectangle $clipRect = null)
  {
    CanvasValidation::id($id);
    SpriteValidation::validateSigned32BitRange($layer);
    if (min($width, $height) < 1 || max($width, $height) > 4096 || $width * $height > 4194304) {
      throw new InvalidArgumentException('Composite dimensions exceed the local raster budget.');
    }
    CanvasCompositeValues::getNumber($opacity, 0, 1);
    $copy = [];
    foreach (CanvasCompositeValues::getList($operations, 0, 256) as $operation) {
      if (!$operation instanceof CanvasCompositeOperation) { throw new InvalidArgumentException('Composite operations must be typed.'); }
      $operation->assertWithin($width, $height);
      $copy[] = $operation;
    }
    $this->operations = $copy;
  }

  public function toArray(): array
  {
    return ['id' => $this->id, 'width' => $this->width, 'height' => $this->height,
      'destination' => $this->destination->toArray(), 'layer' => $this->layer,
      'operations' => array_map(static fn($operation) => $operation->data, $this->operations),
      ...($this->opacity === 1.0 ? [] : ['opacity' => $this->opacity]),
      ...($this->clipRect === null ? [] : ['clipRect' => $this->clipRect->toArray()])];
  }
}
