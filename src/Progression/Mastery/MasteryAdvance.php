<?php

namespace Ichiloto\Engine\Progression\Mastery;

use Ichiloto\Engine\Entities\Skills\Skill;

/**
 * What one change on a character's Mastery Map did: the points it added and
 * the skills learned, in the order they were.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final readonly class MasteryAdvance
{
  /**
   * @param list<Skill> $learned The skills learned, in the order they were.
   * @param int $points The Mastery points added.
   */
  public function __construct(public array $learned = [], public int $points = 0)
  {
  }
}
