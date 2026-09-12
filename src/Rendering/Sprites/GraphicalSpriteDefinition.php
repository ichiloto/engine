<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

/** Immutable graphical intent, independent of world position, Camera and transport. */
final readonly class GraphicalSpriteDefinition
{
  public ?SpriteSourceRect $sourceRect;

  public function __construct(
    public string $asset,
    public int $width,
    public int $height,
    public PresentationSpriteAnchor $anchor = PresentationSpriteAnchor::BOTTOM_CENTER,
    public int $layer = 0,
    ?SpriteSourceRect $sourceRect = null,
    public ?SpriteSheet $sheet = null,
  )
  {
    SpriteValidation::validateDefinition($asset, $width, $height, $layer);
    $this->sourceRect = $sourceRect ?? $sheet?->sourceRect($sheet->idleFrame);
  }

  public function atFrame(int $frame): self
  {
    return $this->sheet === null ? $this : new self($this->asset, $this->width, $this->height,
      $this->anchor, $this->layer, $this->sheet->sourceRect($frame), $this->sheet);
  }
}
