<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

/** The scene owns camera projection and local layout; Terminal glyphs remain independent. */
interface WorldCanvasProviderInterface
{
  /** @return list<PresentationWorldCanvas> Already camera-relative, like selected field sprites. */
  public function getPresentationWorldCanvases(): array;
}
