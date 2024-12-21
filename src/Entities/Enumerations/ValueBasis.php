<?php

namespace Ichiloto\Engine\Entities\Enumerations;

/**
 * The ValueBasis enum.
 *
 * @package Ichiloto\Engine\Entities\Enumerations
 */
enum ValueBasis: string
{
  case PERCENTAGE = 'PERCENTAGE'; // The value is a percentage of the base value.
  case ACTUAL = 'ACTUAL'; // The value is an actual value.
  case OTHER = 'OTHER'; // The value is another value.
}
