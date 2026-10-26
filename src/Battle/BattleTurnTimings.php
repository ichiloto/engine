<?php

namespace Ichiloto\Engine\Battle;

/**
 * Represents the staged delays for a single battle turn.
 *
 * @package Ichiloto\Engine\Battle
 */
class BattleTurnTimings
{
  public function __construct(
    public float $stepForward,
    public float $announcement,
    public float $actionAnimation,
    public float $effectAnimation,
    public float $stepBack,
    public float $statChanges,
    public float $turnOver,
  )
  {
  }

  /** The share of a turn budget the announcement is read for when its category authors no reading time. */
  public const float ANNOUNCEMENT_SHARE = 0.28;

  /**
   * Creates a timing profile by distributing the given total duration.
   *
   * The announcement may be read for its own time, independent of the
   * budget: the budget then paces only the beats around it.
   *
   * @param float $totalDurationSeconds The turn budget its phases share, in seconds.
   * @param float|null $announcementSeconds How long the announcement is read, else its share of the budget.
   * @return self
   */
  public static function fromTotalDuration(float $totalDurationSeconds, ?float $announcementSeconds = null): self
  {
    // Favor the announcement, visible effect beat, and result beat so players can read the action.
    return new self(
      $totalDurationSeconds * 0.07,
      $announcementSeconds ?? $totalDurationSeconds * self::ANNOUNCEMENT_SHARE,
      $totalDurationSeconds * 0.12,
      $totalDurationSeconds * 0.18,
      $totalDurationSeconds * 0.07,
      $totalDurationSeconds * 0.18,
      $totalDurationSeconds * 0.10,
    );
  }

  /**
   * Returns the total duration in seconds.
   *
   * @return float
   */
  public function totalDurationSeconds(): float
  {
    return $this->stepForward
      + $this->announcement
      + $this->actionAnimation
      + $this->effectAnimation
      + $this->stepBack
      + $this->statChanges
      + $this->turnOver;
  }
}
