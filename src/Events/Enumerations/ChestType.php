<?php

namespace Ichiloto\Engine\Events\Enumerations;

/**
 * The ChestType enum.
 *
 * @package Ichiloto\Engine\Events\Enumerations
 */
enum ChestType: string
{
  case COMMON = 'common';
  case RARE = 'rare';
  case EPIC = 'epic';
  case LEGENDARY = 'legendary';
}
