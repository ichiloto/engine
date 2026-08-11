<?php

namespace Ichiloto\Engine\Messaging\Notifications;

use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * Resolves notification timing from semantic presets, project configuration,
 * and the player's accessibility profile.
 *
 * Projects may override the standard hold times under
 * `ui.notifications.durations.short|medium|long` and the default slide time
 * under `ui.notifications.animation_duration`. Explicit float durations still
 * pass through the player's duration scale so accessibility preferences apply
 * to every notification source consistently.
 */
final class NotificationTimingPolicy
{
  public const float DEFAULT_ANIMATION_DURATION = 0.30;

  /**
   * NotificationTimingPolicy constructor.
   */
  private function __construct()
  {
  }

  /**
   * Resolves the stationary hold time in seconds.
   *
   * @param NotificationDuration|float $duration The semantic preset or an explicit base duration.
   * @return float The scaled hold time in seconds.
   */
  public static function duration(NotificationDuration|float $duration): float
  {
    $baseDuration = $duration instanceof NotificationDuration
      ? self::configuredDuration($duration)
      : max(0.0, $duration);

    return $baseDuration * Accessibility::notificationDurationScale();
  }

  /**
   * Resolves an entry or exit animation duration in seconds.
   *
   * Reduced-motion mode suppresses the slide altogether. A caller-supplied
   * duration otherwise takes precedence over the project default.
   *
   * @param float|null $duration An explicit duration, or null for project policy.
   * @return float The animation duration in seconds.
   */
  public static function animationDuration(?float $duration = null): float
  {
    if (Accessibility::prefersReducedMotion()) {
      return 0.0;
    }

    if ($duration !== null) {
      return max(0.0, $duration);
    }

    return self::configuredFloat(
      'ui.notifications.animation_duration',
      self::DEFAULT_ANIMATION_DURATION
    );
  }

  /**
   * Resolves one semantic duration preset through project configuration.
   *
   * @param NotificationDuration $duration The preset.
   * @return float The configured base duration in seconds.
   */
  private static function configuredDuration(NotificationDuration $duration): float
  {
    return self::configuredFloat(
      sprintf('ui.notifications.durations.%s', strtolower($duration->name)),
      $duration->toFloat()
    );
  }

  /**
   * Reads a non-negative floating-point project setting.
   *
   * @param string $path The project-config path.
   * @param float $default The fallback value.
   * @return float The validated setting.
   */
  private static function configuredFloat(string $path, float $default): float
  {
    if (ConfigStore::doesntHave(ProjectConfig::class)) {
      return $default;
    }

    $configured = config(ProjectConfig::class, $path, $default);

    return is_numeric($configured) ? max(0.0, floatval($configured)) : $default;
  }
}
