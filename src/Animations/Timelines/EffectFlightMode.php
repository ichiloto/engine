<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

/** How a captured route is painted; the command owns contact and outcomes. */
enum EffectFlightMode: string
{
  case PROJECTILE = 'projectile';
  case BEAM = 'beam';
}
