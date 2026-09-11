<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;

/** Immutable graphical intent, independent of world position, Camera and transport. */
final readonly class GraphicalSpriteDefinition
{
  public function __construct(
    public string $asset,
    public int $width,
    public int $height,
    public PresentationSpriteAnchor $anchor = PresentationSpriteAnchor::BOTTOM_CENTER,
    public int $layer = 0,
  )
  {
    SpriteValidation::validateDefinition($asset, $width, $height, $layer);
  }
}
