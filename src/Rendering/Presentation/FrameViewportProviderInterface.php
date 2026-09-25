<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;

interface FrameViewportProviderInterface
{
  /** @param list<PresentationSprite> $sprites @param list<PresentationTileBatch> $tiles */
  public function getPresentationViewport(ConsolePresentationSnapshot $snapshot, array $sprites, array $tiles): ?PresentationViewport;
}
