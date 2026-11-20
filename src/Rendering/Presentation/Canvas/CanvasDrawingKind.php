<?php
declare(strict_types=1);
namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

/** Equal-layer paint order shared by the canvas wire and software/native painters. */
enum CanvasDrawingKind: int
{
  case IMAGE = 0;
  case COMPOSITE = 1;
  case INDICATOR = 2;
  case TEXT = 3;
  case LABEL = 4;
  case GROUP = 5;
}
