<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

enum EffectAnchorLifetime: string
{
  case FOLLOW = 'follow';
  case EFFECT_START = 'effect-start';
}
