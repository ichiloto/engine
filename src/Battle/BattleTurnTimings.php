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

  /**
   * Creates a timing profile by distributing the given total duration.
   *
   * @param float $totalDurationSeconds The total action duration in seconds.
   * @return self
   */
  public static function fromTotalDuration(float $totalDurationSeconds): self
  {
    // Favor the announcement, visible effect beat, and result beat so players can read the action.
    return new self(
      $totalDurationSeconds * 0.07,
      $totalDurationSeconds * 0.28,
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
