<?php

namespace Ichiloto\Engine\Entities\Skills;

/**
 * Describes whether a skill is broadly learnable or character-locked.
 *
 * @package Ichiloto\Engine\Entities\Skills
 */
enum SkillAvailability: string
{
  case GENERAL = 'general';
  case UNIQUE = 'unique';

  /**
   * Resolves an availability value from project data.
   *
   * @param mixed $value The raw configured value.
   * @return self The resolved availability.
   */
  public static function fromValue(mixed $value): self
  {
    if ($value instanceof self) {
      return $value;
    }

    return self::tryFrom(strtolower(trim(strval($value)))) ?? self::GENERAL;
  }
}
