<?php

namespace Ichiloto\Engine\Entities\Enumerations;

/**
 * The ItemUserType enumeration.
 *
 * @package Ichiloto\Engine\Entities\Enumerations
 */
enum ItemUserType: string
{
  case ALL = 'All';
  case CHARACTER_SPECIFIC = 'Character Specific';
  case CLASS_SPECIFIC = 'Class Specific';
}
