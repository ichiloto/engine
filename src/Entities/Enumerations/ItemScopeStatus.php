<?php

namespace Ichiloto\Engine\Entities\Enumerations;

/**
 * The ItemScopeStatus enumeration.
 *
 * @package Ichiloto\Engine\Entities\Enumerations
 */
enum ItemScopeStatus: string
{
  case ALIVE = 'Alive';
  case DEAD = 'Dead';
  case ANY = 'Any';
}
