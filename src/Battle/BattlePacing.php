<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Enumerations\BattleActionCategory;
use Ichiloto\Engine\Battle\Enumerations\BattlePace;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * Resolves configurable message and animation pacing for battles.
 *
 * @package Ichiloto\Engine\Battle
 */
class BattlePacing
{
  /** The `ui.battle` key under which a project authors announcement reading times, by category then pace. */
  public const string ANNOUNCEMENT_SECONDS_KEY = 'announcement_seconds';
  /** The shortest and longest an announcement is read for, in seconds. */
  public const float MIN_ANNOUNCEMENT_SECONDS = 0.1;
  public const float MAX_ANNOUNCEMENT_SECONDS = 10.0;

  /**
   * Creates a new battle pacing profile.
   *
   * @param BattlePace $messagePace The preset for battle messages.
   * @param BattlePace $animationPace The preset for battle turn timing.
   * @param float|null $messageDurationOverride An explicit legacy message duration in seconds.
   * @param array<string, array<string, mixed>> $announcementSeconds Authored announcement reading times in
   *   seconds, by category value then pace value; a category or pace left out reads for the Engine's time.
   */
  public function __construct(
    protected BattlePace $messagePace = BattlePace::SLOW,
    protected BattlePace $animationPace = BattlePace::SLOW,
    protected ?float $messageDurationOverride = null,
    protected array $announcementSeconds = [],
  )
  {
  }

  /**
   * Creates a pacing profile from the project config.
   *
   * @return self
   */
  public static function fromConfig(): self
  {
    if (! ConfigStore::has(ProjectConfig::class)) {
      return new self();
    }

    $battleUiConfig = config(ProjectConfig::class, 'ui.battle', []);

    return is_array($battleUiConfig) ? self::fromBattleUiConfig($battleUiConfig) : new self();
  }

  /** @param array<string, mixed> $battleUiConfig */
  public static function fromBattleUiConfig(array $battleUiConfig): self
  {
    $messagePaceValue = $battleUiConfig['message_pace'] ?? $battleUiConfig['message_speed'] ?? null;
    $legacyInfoDisplaySpeed = $battleUiConfig['info_display_speed'] ?? null;
    $resolvedMessagePaceValue = $messagePaceValue ?? $legacyInfoDisplaySpeed;
    $animationPaceValue = $battleUiConfig['animation_pace']
      ?? $battleUiConfig['animation_speed']
      ?? $resolvedMessagePaceValue;

    return new self(
      BattlePace::fromMixed($resolvedMessagePaceValue, BattlePace::SLOW),
      BattlePace::fromMixed($animationPaceValue, BattlePace::SLOW),
      $messagePaceValue === null && is_numeric($legacyInfoDisplaySpeed)
        ? floatval($legacyInfoDisplaySpeed)
        : null,
      is_array($battleUiConfig[self::ANNOUNCEMENT_SECONDS_KEY] ?? null) ? $battleUiConfig[self::ANNOUNCEMENT_SECONDS_KEY] : [],
    );
  }

  public function getAnimationPace(): BattlePace
  {
    return $this->animationPace;
  }

  /**
   * Returns the auto-hide duration for general battle messages.
   *
   * @return float
   */
  public function getMessageDurationSeconds(): float
  {
    return clamp(
      $this->messageDurationOverride ?? $this->messagePace->messageDurationSeconds(),
      0.1,
      10.0
    );
  }

  /**
   * Returns the staged timings for the provided action.
   *
   * @param BattleAction|null $action The action being resolved.
   * @return BattleTurnTimings
   */
  public function getTurnTimings(?BattleAction $action): BattleTurnTimings
  {
    $category = BattleActionCategory::fromAction($action);

    return BattleTurnTimings::fromTotalDuration(
      $category->totalDurationSeconds($this->animationPace),
      $this->getAnnouncementSeconds($category),
    );
  }

  /**
   * Returns how long the given category's announcement is read at the
   * animation pace: the project's authored time, kept within
   * {@see self::MIN_ANNOUNCEMENT_SECONDS} and {@see self::MAX_ANNOUNCEMENT_SECONDS},
   * else the Engine's ({@see BattleActionCategory::getAnnouncementSeconds()}).
   *
   * @param BattleActionCategory $category The action's timing category.
   * @return float
   */
  public function getAnnouncementSeconds(BattleActionCategory $category): float
  {
    $authored = $this->announcementSeconds[$category->value][$this->animationPace->value] ?? null;

    return (is_int($authored) || is_float($authored)) && is_finite((float)$authored)
      ? clamp((float)$authored, self::MIN_ANNOUNCEMENT_SECONDS, self::MAX_ANNOUNCEMENT_SECONDS)
      : $category->getAnnouncementSeconds($this->animationPace);
  }
}
