<?php

namespace Ichiloto\Engine\Entities\Roles;

use Ichiloto\Engine\Entities\Skills\Skill;

/**
 * Class SkillToLearn. Represents a skill that a character can learn.
 *
 * @package Ichiloto\Engine\Entities\Roles
 */
readonly class SkillToLearn
{
  /**
   * SkillToLearn constructor.
   *
   * @param int $level The level at which the skill can be learned.
   * @param Skill $skill The skill to learn.
   * @param string $note The note.
   */
  public function __construct(
    public int $level,
    public Skill $skill,
    public string $note = ''
  )
  {
  }
}