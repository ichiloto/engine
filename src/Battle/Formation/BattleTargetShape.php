<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Formation;

enum BattleTargetShape: string
{
  case SCOPE = 'scope';
  case LINE = 'line';
  case FRONT_ROW = 'front_row';
  case BACK_ROW = 'back_row';
}
