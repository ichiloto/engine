<?php

namespace Ichiloto\Engine\Entities\Skills;

/**
 * Represents the skill invocation.
 *
 * @package Ichiloto\Engine\Entities\Skills
 */
class SkillInvocation
{
  /**
   * SkillInvocation constructor.
   *
   * @param string $message The message to display when the skill is invoked.
   * @param int $speed The speed of the skill. This determines the order of the skill execution.
   * @param int $accuracy The accuracy of the skill. This determines the chance of the skill hitting the target.
   * @param int $repeat The number of times the skill can be applied.
   */
  public function __construct(
    public string $message = '$1 casts $2!',
    public int $speed = 0,
    public int $accuracy = 0,
    public int $repeat = 1,
  )
  {
  }
}