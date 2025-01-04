<?php

namespace Ichiloto\Engine\Entities\Enemies;

use Ichiloto\Engine\Entities\Interfaces\SkillInterface;

/**
 * Represents the action pattern of an enemy.
 *
 * @package Ichiloto\Engine\Entities\Enemies
 */
class ActionPattern
{
  /**
   * ActionPattern constructor.
   *
   * @param SkillInterface $skill
   * @param int $rating
   * @param ActionCondition $condition
   */
  public function __construct(
    protected(set) SkillInterface $skill,
    protected(set) int $rating,
    protected(set) ActionCondition $condition
  )
  {
  }
}