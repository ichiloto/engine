<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;

interface FrameViewportProviderInterface
{
  /** @param list<PresentationSprite> $sprites @param list<PresentationTileBatch> $tiles */
  public function getPresentationViewport(ConsolePresentationSnapshot|ConsolePresentationChanges $snapshot, array $sprites, array $tiles = []): ?PresentationViewport;
}
