<?php

namespace Ichiloto\Engine\Entities\Enumerations;

/**
 * The ValueBasis enum.
 *
 * @package Ichiloto\Engine\Entities\Enumerations
 */
enum ValueBasis
{
  case PERCENTAGE; // The value is a percentage of the base value.
  case ACTUAL; // The value is an actual value.
  case OTHER; // The value is another value.
}
