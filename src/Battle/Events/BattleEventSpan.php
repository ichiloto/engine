<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Events;

/**
 * How often a troop event page may run while its conditions hold.
 *
 * @package Ichiloto\Engine\Battle\Events
 */
enum BattleEventSpan: string
{
  /** At most once in the battle. */
  case BATTLE = 'battle';
  /** At most once in each turn. */
  case TURN = 'turn';
  /** At every check where its conditions hold. */
  case MOMENT = 'moment';
}
