<?php

namespace Ichiloto\Engine\Entities\Enumerations;

/**
 * Represents the action condition type.
 *
 * @package Ichiloto\Engine\Enumerations
 */
enum ActionConditionType: string
{
  case ALWAYS = 'Always';
  case TURN = 'Turn';
  case HP = 'HP';
  case MP = 'MP';
  case Status = 'Status';
  case PARTY_LEVEL = 'Party Level';
  case SWITCH = 'SWITCH';
}
