<?php

namespace Ichiloto\Engine\Battle\Enumerations;

/**
 * Represents the available battle pacing presets.
 *
 * @package Ichiloto\Engine\Battle\Enumerations
 */
enum BattlePace: string
{
  case FAST = 'fast';
  case MEDIUM = 'medium';
  case SLOW = 'slow';

  /**
   * Resolves a config value into a battle pace preset.
   *
   * @param mixed $value The configured value.
   * @param self $default The fallback pace.
   * @return self
   */
  public static function fromMixed(mixed $value, self $default = self::MEDIUM): self
  {
    if ($value instanceof self) {
      return $value;
    }

    if (is_numeric($value)) {
      return match (true) {
        $value <= 2 => self::FAST,
        $value <= 4 => self::MEDIUM,
        default => self::SLOW,
      };
    }

    if (! is_string($value)) {
      return $default;
    }

    return match (strtolower(trim($value))) {
      'fast' => self::FAST,
      'medium' => self::MEDIUM,
      'slow' => self::SLOW,
      default => $default,
    };
  }

  /**
   * Returns the representative info-panel duration in seconds for this pace.
   *
   * @return float
   */
  public function messageDurationSeconds(): float
  {
    return match ($this) {
      self::FAST => 0.65,
      self::MEDIUM => 1.25,
      self::SLOW => 2.5,
    };
  }
}
