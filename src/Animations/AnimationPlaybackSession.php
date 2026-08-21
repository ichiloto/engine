<?php

namespace Ichiloto\Engine\Animations;

/** Non-blocking traversal state shared by field and blocking animation hosts. */
final class AnimationPlaybackSession
{
  protected float $accumulatedSeconds = 0.0;
  protected(set) int $currentFrame = 1;
  protected(set) bool $isComplete = false;

  public function __construct(
    public readonly Animation $animation,
    public readonly float $secondsPerFrame = 0.12,
  )
  {
  }

  /** @return int[] Frames entered by this elapsed-time update. */
  public function update(float $deltaSeconds): array
  {
    if ($this->isComplete || $deltaSeconds <= 0.0) {
      return [];
    }

    $this->accumulatedSeconds += $deltaSeconds;
    $crossed = [];
    $duration = max(0.01, $this->secondsPerFrame);

    while ($this->accumulatedSeconds + PHP_FLOAT_EPSILON >= $duration) {
      $this->accumulatedSeconds = max(0.0, $this->accumulatedSeconds - $duration);

      if ($this->currentFrame >= $this->animation->maxFrames) {
        $this->isComplete = true;
        $this->accumulatedSeconds = 0.0;
        break;
      }

      $this->currentFrame++;
      $crossed[] = $this->currentFrame;
    }

    return $crossed;
  }

  public function cancel(): void
  {
    $this->isComplete = true;
  }
}
