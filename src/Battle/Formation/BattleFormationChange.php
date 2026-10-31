<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Formation;

enum BattleFormationChange: string
{
  case SHUFFLE = 'shuffle';
  case MOVE_TO_FRONT = 'move_to_front';
}
