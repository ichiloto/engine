<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/**
 * A Mastery points total counting from what it was to what it is after a
 * spend, over a short time on the menu's presentation clock. The points are
 * already spent; this only says what a renderer shows meanwhile. Terminal
 * and reduced-motion presentations show the new total at once instead.
 */
final readonly class MasteryPointsCount
{
  /** How long the count takes, in seconds. */
  public const float DURATION = 0.4;

  /**
   * @param int $from The total before the spend.
   * @param int $to The total after it.
   * @param float $startedAt When the count began, on the presentation clock.
   */
  public function __construct(public int $from, public int $to, public float $startedAt) {}

  /** The total to show at a moment on the presentation clock. */
  public function getValueAt(float $time): int
  {
    $progress = min(1.0, max(0.0, ($time - $this->startedAt) / self::DURATION));

    return (int)round($this->from + ($this->to - $this->from) * $progress);
  }

  /** Whether the shown total is still moving at a moment on the presentation clock. */
  public function isCountingAt(float $time): bool
  {
    return $this->from !== $this->to && $time >= $this->startedAt && $time < $this->startedAt + self::DURATION;
  }
}
