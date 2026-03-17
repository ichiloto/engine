<?php

namespace Ichiloto\Engine\Entities\Abilities;

/**
 * Defines the available learned-ability sort orders.
 *
 * @package Ichiloto\Engine\Entities\Abilities
 */
enum AbilitySortOrder: string
{
  case A_TO_Z = 'A-Z';
  case Z_TO_A = 'Z-A';
}
