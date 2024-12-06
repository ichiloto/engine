<?php

namespace Ichiloto\Engine\Entities\Enumerations;

/**
 * The ItemScopeSide enumeration.
 *
 * @package Ichiloto\Engine\Entities\Enumerations
 */
enum ItemScopeSide: string
{
  case NONE = 'None';
  case ENEMY = 'Enemy';
  case ALLY = 'Ally';
  case ENEMY_ALLY = 'Enemy & Ally';
  case USER = 'User';
}
