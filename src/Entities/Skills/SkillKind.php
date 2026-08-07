<?php

namespace Ichiloto\Engine\Entities\Skills;

/**
 * Describes the engine-level behavior family of a skill.
 *
 * Player-facing projects may label summon-style actions however they like;
 * this enum is the stable data contract the battle engine relies on.
 *
 * @package Ichiloto\Engine\Entities\Skills
 */
enum SkillKind: string
{
  case REGULAR = 'regular';
  case SPECIAL = 'special';
  case SUMMON = 'summon';

  /**
   * Resolves a kind value from project data.
   *
   * @param mixed $value The raw configured value.
   * @return self The resolved skill kind.
   */
  public static function fromValue(mixed $value): self
  {
    if ($value instanceof self) {
      return $value;
    }

    return self::tryFrom(strtolower(trim(strval($value)))) ?? self::REGULAR;
  }
}
