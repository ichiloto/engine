<?php

namespace Ichiloto\Engine\Entities\Magic;

/**
 * Represents the battlefield animation category for a magic spell.
 *
 * @package Ichiloto\Engine\Entities\Magic
 */
enum MagicEffectType: string
{
  case RESTORATIVE = 'restorative';
  case DESTRUCTIVE = 'destructive';
  case BUFF = 'buff';
  case DEBUFF = 'debuff';
}
