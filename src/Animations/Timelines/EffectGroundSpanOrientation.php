<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

/** The source cell's allowed rigid orientation between its two captured anchors. */
enum EffectGroundSpanOrientation: string
{
  case RIGID = 'rigid';
  case UPRIGHT = 'upright';
}
