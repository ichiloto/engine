<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

enum EffectFlightRoute: string
{
  case WAYPOINTS = 'waypoints';
  case STRAIGHT = 'straight';
}
