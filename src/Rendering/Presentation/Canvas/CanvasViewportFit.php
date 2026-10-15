<?php

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

/** How a complete canvas fits the available window; logical drawing coordinates stay unchanged. */
enum CanvasViewportFit: string
{
  case NATIVE = 'native';
  case CONTAIN = 'contain';
}
