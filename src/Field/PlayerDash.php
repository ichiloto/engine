<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * Whether the player's next held step dashes, RPG Maker's dash: while the
 * `dash` action is held, each step takes the walking time divided by the
 * project's speed multiplier ({@see DashSettings}). With the player's Always
 * Dash option on, the player dashes unless `dash` is held.
 *
 * Only input that reports held controls can dash. The Terminal reports key
 * events, never a held control, and walks one validated step per key event
 * at the keyboard's own repeat rate, with no step duration for a dash to
 * shorten; dashing, its control and its option are not offered there.
 *
 * Dashing changes only how long a step takes. Each step is still one
 * validated cell, so collision, triggers, events and encounter steps count
 * exactly as walking does.
 */
final class PlayerDash
{
  /** The semantic action held to dash. */
  public const string ACTION = 'dash';
  /** The player's Always Dash option; the project's config.php may author its first value. */
  public const string CONFIG_ALWAYS_DASH = 'field.always_dash';

  /** Whether this game offers dashing on the current input: the project enables it and the input reports held controls. */
  public static function isAvailable(): bool
  {
    return self::getSettings()->enabled && InputManager::isHeldInputAvailable();
  }

  /**
   * How many times faster than walking a step decided now goes: the
   * project's multiplier while dashing, otherwise 1.
   *
   * @param bool $mapDisablesDashing Whether the current map turns dashing off.
   */
  public static function getSpeedMultiplier(bool $mapDisablesDashing = false): float
  {
    if ($mapDisablesDashing || ! self::isAvailable()) {
      return 1.0;
    }
    $held = InputManager::isButtonHeld(self::ACTION);

    return (self::isAlwaysDashOn() ? ! $held : $held) ? self::getSettings()->speedMultiplier : 1.0;
  }

  private static function isAlwaysDashOn(): bool
  {
    return ConfigStore::has(ProjectConfig::class) && boolval(config(ProjectConfig::class, self::CONFIG_ALWAYS_DASH, false));
  }

  private static function getSettings(): DashSettings
  {
    $settings = ConfigStore::has(DashSettings::class) ? ConfigStore::get(DashSettings::class) : null;

    return $settings instanceof DashSettings ? $settings : new DashSettings();
  }
}
