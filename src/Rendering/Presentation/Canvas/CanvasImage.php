<?php

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

final readonly class CanvasImage
{
  public function __construct(
    public string $id,
    public string $asset,
    public CanvasRectangle $destination,
    public int $layer = 0,
    public ?SpriteSourceRect $sourceRect = null,
    public float $opacity = 1,
    public ?CanvasRectangle $clipRect = null,
  )
  {
    CanvasValidation::id($id);
    SpriteValidation::validateAssetPath($asset);
    SpriteValidation::validateSigned32BitRange($layer);
    if (strlen($asset) > 4096 || !is_finite($opacity) || $opacity < 0 || $opacity > 1) {
      throw new InvalidArgumentException('Canvas assets are limited to 4096 bytes and opacity to finite values in 0..1.');
    }
  }

  /** @return array<string, mixed> */
  public function toArray(): array
  {
    return ['id' => $this->id, 'asset' => $this->asset, 'destination' => $this->destination->toArray(),
      'layer' => $this->layer,
      ...($this->sourceRect === null ? [] : ['sourceRect' => $this->sourceRect->toArray()]),
      ...($this->opacity === 1.0 ? [] : ['opacity' => $this->opacity]),
      ...($this->clipRect === null ? [] : ['clipRect' => $this->clipRect->toArray()])];
  }
}
