<?php

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

interface CanvasProviderInterface
{
  /** Null selects the existing grid presentation for the whole frame. */
  public function getPresentationCanvas(): ?PresentationCanvas;
}
