<?php

namespace Ichiloto\Engine\Entities;

use Ichiloto\Engine\Entities\Skills\Skill;

class CharacterVocation
{
  /**
   * CharacterVocation constructor.
   *
   * @param string $name The name of the vocation.
   * @param array{level: int, value: int} $experienceLevels The experience levels.
   * @param array<array{level: int, value: int}> $parameterCurves The parameter curves.
   * @param array<int, Skill> $skills The skills of the vocation.
   * @param array{type: string, content: mixed} $traits The traits of the vocation.
   * @param string $note The note of the vocation.
   */
  public function __construct(
    protected(set) string $name,
    protected(set) array $experienceLevels = [],
    protected(set) array $parameterCurves = [],
    protected(set) array $skills = [],
    protected(set) array $traits = [],
    protected(set) string $note = ''
  )
  {
  }
}