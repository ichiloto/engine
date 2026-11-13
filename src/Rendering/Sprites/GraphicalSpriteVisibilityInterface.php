<?php

namespace Ichiloto\Engine\Rendering\Sprites;

/** Explicitly withheld artwork keeps its Terminal role without a graphical glyph fallback. */
interface GraphicalSpriteVisibilityInterface extends GraphicalSpriteProviderInterface
{
  public bool $isGraphicallyVisible { get; }
}
