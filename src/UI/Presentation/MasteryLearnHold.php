<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/**
 * Learn held down on one Mastery node, on the menu's presentation clock.
 * Nothing is spent while it is held: the node fills and the shown points
 * drain as a preview, and the menu learns the node once, exactly, when the
 * hold completes. Letting go, moving the focus or losing the window cancels
 * it and the shown total returns. Under reduced motion the hold takes as
 * long, without the fill or drain; the total changes when it completes.
 */
final readonly class MasteryLearnHold
{
  /** How long Learn is held to learn, in seconds. */
  public const float DURATION = 1.0;

  /**
   * @param string $node The node being learned.
   * @param int $points The points the character holds.
   * @param int $cost The node's price.
   * @param float $startedAt When the hold began, on the presentation clock.
   */
  public function __construct(public string $node, public int $points, public int $cost, public float $startedAt) {}

  /** How far the hold has come at a moment, from 0 to 1. */
  public function getProgressAt(float $time): float
  {
    return min(1.0, max(0.0, ($time - $this->startedAt) / self::DURATION));
  }

  /** Whether the hold has lasted long enough to learn at a moment. */
  public function isCompleteAt(float $time): bool
  {
    return $time - $this->startedAt >= self::DURATION;
  }

  /** The points a drawn total shows at a moment while the hold drains them. */
  public function getPointsAt(float $time): int
  {
    return (int)round($this->points - $this->cost * $this->getProgressAt($time));
  }

  /** Whether the shown total is still draining at a moment. */
  public function isDrainingAt(float $time): bool
  {
    return $this->cost > 0 && $time >= $this->startedAt && ! $this->isCompleteAt($time);
  }
}
