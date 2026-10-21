<?php

namespace Ichiloto\Engine\Progression\Mastery;

use Ichiloto\Engine\Entities\Skills\Skill;

/**
 * What one award of EXP did on a character's Mastery Map.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final readonly class MasteryAdvance
{
  /**
   * @param list<Skill> $learned The skills learned, in the order they were.
   * @param string|null $studying The node now studied, when one is open.
   */
  public function __construct(public array $learned = [], public ?string $studying = null)
  {
  }
}
