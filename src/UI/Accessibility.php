<?php

namespace Ichiloto\Engine\UI;

use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * Player-facing accessibility preferences.
 *
 * Every option defaults to the engine's existing behaviour, so a project
 * that sets nothing renders exactly as before. Options are read from the
 * project config under `accessibility.*`:
 *
 * - `reducedMotion` — suppress blinking highlights and shorten flourishes
 * - `disableBlink`  — suppress blinking only (implied by reduced motion)
 * - `highContrast`  — draw selections in a high-contrast pair
 * - `textSpeedScale`— multiply dialogue typing speed (2.0 is twice as fast)
 * - `notificationDurationScale` — multiply transient notification hold times
 *
 * @package Ichiloto\Engine\UI
 */
final class Accessibility
{
  /**
   * Accessibility constructor.
   */
  private function __construct()
  {
  }

  /**
   * Determines whether motion effects should be reduced.
   *
   * @return bool True when the player asked for reduced motion.
   */
  public static function prefersReducedMotion(): bool
  {
    return (bool) self::setting('reducedMotion', false);
  }

  /**
   * Determines whether blinking highlights are allowed.
   *
   * Reduced motion implies no blinking, so a project only needs one switch.
   *
   * @return bool True when blinking may be used.
   */
  public static function allowsBlink(): bool
  {
    if (self::prefersReducedMotion()) {
      return false;
    }

    return ! (bool) self::setting('disableBlink', false);
  }

  /**
   * Determines whether high-contrast styling is requested.
   *
   * @return bool True when high contrast is on.
   */
  public static function prefersHighContrast(): bool
  {
    return (bool) self::setting('highContrast', false);
  }

  /**
   * Returns the dialogue text-speed multiplier.
   *
   * @return float The multiplier; 1.0 leaves the configured speed alone.
   */
  public static function textSpeedScale(): float
  {
    $scale = self::setting('textSpeedScale', 1.0);

    return is_numeric($scale) ? max(0.1, floatval($scale)) : 1.0;
  }

  /**
   * Returns the transient-notification duration multiplier.
   *
   * @return float The multiplier; 1.0 uses the standard project timing.
   */
  public static function notificationDurationScale(): float
  {
    $scale = self::setting('notificationDurationScale', 1.0);

    return is_numeric($scale) ? max(0.1, floatval($scale)) : 1.0;
  }

  /**
   * Reads one accessibility setting from the project config.
   *
   * @param string $key The setting key under `accessibility.`.
   * @param mixed $default The value returned when unset.
   * @return mixed The setting value.
   */
  protected static function setting(string $key, mixed $default): mixed
  {
    if (! ConfigStore::has(ProjectConfig::class)) {
      return $default;
    }

    return config(ProjectConfig::class, "accessibility.$key", $default);
  }
}
