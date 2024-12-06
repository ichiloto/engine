<?php

namespace Ichiloto\Engine\Entities\Enumerations;

/**
 * The Occasion enumeration.
 *
 * @package Ichiloto\Engine\Entities\Enumerations
 */
enum Occasion: string
{
  case ALWAYS = 'Always';
  case BATTLE_SCREEN = 'Battle Screen';
  case MENU_SCREEN = 'Menu Screen';
  case NEVER = 'Never';
}
