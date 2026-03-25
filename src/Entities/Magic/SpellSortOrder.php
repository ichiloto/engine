<?php

namespace Ichiloto\Engine\Entities\Magic;

/**
 * Defines the available learned-spell ordering modes.
 *
 * @package Ichiloto\Engine\Entities\Magic
 */
enum SpellSortOrder: string
{
  case A_TO_Z = 'A-Z';
  case Z_TO_A = 'Z-A';
}
