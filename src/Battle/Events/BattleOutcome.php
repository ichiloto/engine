<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Events;

/**
 * The outcomes a battle can end with. The values are the ones field scripts
 * read from a battle result.
 *
 * @package Ichiloto\Engine\Battle\Events
 */
enum BattleOutcome: string
{
  case VICTORY = 'victory';
  case ESCAPE = 'escape';
  case DEFEAT = 'defeat';
}
