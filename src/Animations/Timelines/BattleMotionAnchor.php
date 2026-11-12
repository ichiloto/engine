<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

enum BattleMotionAnchor: string
{
  case CASTER = 'caster';
  case TARGET = 'target';
}
