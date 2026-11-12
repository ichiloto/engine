<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\Skills\BasicSkill;

/**
 * A battler whose definition may choose the skill it attacks with.
 *
 * Actors and enemies author `attackSkill` the same way. Absence keeps the
 * Engine's built-in physical attack.
 *
 * @package Ichiloto\Engine\Battle
 */
interface InherentAttacker
{
  /** The battle-usable basic skill this battler attacks with, or null for the built-in attack. */
  public ?BasicSkill $attackSkill { get; }
}
