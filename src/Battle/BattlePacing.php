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
  /**
   * Creates a new battle pacing profile.
   *
   * @param BattlePace $messagePace The preset for battle messages.
   * @param BattlePace $animationPace The preset for battle turn timing.
   * @param float|null $messageDurationOverride An explicit legacy message duration in seconds.
   */
  public function __construct(
    protected BattlePace $messagePace = BattlePace::SLOW,
    protected BattlePace $animationPace = BattlePace::SLOW,
    protected ?float $messageDurationOverride = null,
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

    if (! is_array($battleUiConfig)) {
      return new self();
    }

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
    );
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
      $category->totalDurationSeconds($this->animationPace)
    );
  }
}
