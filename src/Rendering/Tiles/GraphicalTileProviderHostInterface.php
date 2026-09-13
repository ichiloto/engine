<?php

namespace Ichiloto\Engine\Rendering\Tiles;

use Ichiloto\Engine\Rendering\Presentation\PresentationTileBatch;

interface GraphicalTileProviderHostInterface
{
  /** @return list<PresentationTileBatch> Empty when this scene has no eligible terrain presentation. */
  public function getGraphicalTileBatches(): array;
}
