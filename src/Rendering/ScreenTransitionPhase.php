<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering;

enum ScreenTransitionPhase: string
{
  case GATHER = 'gather';
  case COVER = 'cover';
  case HOLD = 'hold';
  case REVEAL = 'reveal';
  case COMPLETE = 'complete';
  case CANCELLED = 'cancelled';
}
