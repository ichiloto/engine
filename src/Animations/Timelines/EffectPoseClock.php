<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

/** The command phase that supplies the start of the caster's action pose. */
enum EffectPoseClock: string
{
  case ANNOUNCEMENT = 'announcement';
  case TARGET = 'target';
}
