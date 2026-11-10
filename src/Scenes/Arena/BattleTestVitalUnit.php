<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

/** How a battle test member's starting HP or MP is counted. */
enum BattleTestVitalUnit: string
{
  /** A share of the member's maximum, 0 to 100. */
  case PERCENT = 'percent';
  /** Exact points, at most the member's maximum. */
  case POINTS = 'points';
}
