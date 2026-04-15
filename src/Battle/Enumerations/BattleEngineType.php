<?php

namespace Ichiloto\Engine\Battle\Enumerations;

/**
 * Enumerates the supported battle engine styles.
 *
 * @package Ichiloto\Engine\Battle\Enumerations
 */
enum BattleEngineType: string
{
  case TRADITIONAL = 'traditional';
  case ACTIVE_TIME = 'active_time';

  /**
   * Resolves a stored engine value safely.
   *
   * @param mixed $value The stored engine value.
   * @return self
   */
  public static function fromValue(mixed $value): self
  {
    if ($value instanceof self) {
      return $value;
    }

    if (is_string($value)) {
      foreach (self::cases() as $case) {
        if ($case->value === strtolower(trim($value))) {
          return $case;
        }
      }
    }

    return self::TRADITIONAL;
  }
}
