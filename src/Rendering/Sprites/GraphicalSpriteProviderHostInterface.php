<?php

namespace Ichiloto\Engine\Rendering\Sprites;

/** Scenes opt in only while their graphical providers own presentation. */
interface GraphicalSpriteProviderHostInterface
{
  /** @return iterable<GraphicalSpriteProviderInterface> */
  public function getGraphicalSpriteProviders(): iterable;
}
