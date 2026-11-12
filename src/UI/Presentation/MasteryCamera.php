<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/**
 * Where the Mastery board's view is centred, in board lanes and stages, how
 * many stages above that place it keeps in frame (up to what the place hangs
 * from, which a wrapped row of siblings sets further up), and the short pan
 * that follows the focus from one place to the next. Reduced motion and the
 * Terminal start each view where it ends.
 */
final readonly class MasteryCamera
{
  /** How long the view takes to follow the focus, in seconds. */
  public const float PAN_DURATION = 0.28;

  public function __construct(
    public float $fromLane,
    public float $fromStage,
    public float $toLane,
    public float $toStage,
    public float $startedAt = 0.0,
    public float $fromAbove = 1.0,
    public float $toAbove = 1.0,
  ) {}

  /** A view that rests on one place, keeping a number of stages above it in frame. */
  public static function createAt(float $lane, float $stage, float $above = 1.0): self
  {
    return new self($lane, $stage, $lane, $stage, 0.0, $above, $above);
  }

  /** The view moving on from where this one is at a moment toward another place; at once when motion is reduced. */
  public function createMove(float $lane, float $stage, float $time, bool $reducedMotion, float $above = 1.0): self
  {
    if ($reducedMotion) {
      return self::createAt($lane, $stage, $above);
    }
    [$fromLane, $fromStage, $fromAbove] = $this->getCentreAt($time);

    return new self($fromLane, $fromStage, $lane, $stage, $time, $fromAbove, $above);
  }

  /**
   * The view's centre at a moment, easing out toward where it ends.
   *
   * @return array{float, float, float} Lane, stage and the stages kept above it.
   */
  public function getCentreAt(float $time): array
  {
    $t = min(1.0, max(0.0, ($time - $this->startedAt) / self::PAN_DURATION));
    $eased = 1 - (1 - $t) ** 3;

    return [$this->fromLane + ($this->toLane - $this->fromLane) * $eased, $this->fromStage + ($this->toStage - $this->fromStage) * $eased,
      $this->fromAbove + ($this->toAbove - $this->fromAbove) * $eased];
  }
}
