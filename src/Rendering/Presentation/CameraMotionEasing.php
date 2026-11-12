<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

/** Presentation interpolation selected by the camera owner. */
enum CameraMotionEasing: string
{
  case LINEAR = 'linear';
  case SMOOTHSTEP = 'smoothstep';
}
