<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;

/** One visual's clock and lifetime, independent of walking and dialogue continuations. */
final class FieldPosePlayback
{
  private float $elapsedSeconds = 0.0;
  private float $elapsedCompensation = 0.0;
  private bool $paused = false;
  private bool $released = false;
  private ?string $lastFailure = null;

  public function __construct(public readonly FieldPoseAnimation $animation, private readonly string $assetRoot,
    private readonly string $context) {}

  public function advance(float $seconds, bool $reducedMotion = false): void
  {
    if (!is_finite($seconds) || $seconds < 0) {
      throw new InvalidArgumentException('Field pose delta must be finite and non-negative.');
    }
    if ($this->paused || $this->released || $reducedMotion) { return; }
    $duration = $this->animation->durationSeconds;
    if (!$this->animation->loop) {
      $this->elapsedSeconds = min($duration, $this->elapsedSeconds + min($seconds, $duration));
      return;
    }
    // Preserve full elapsed precision at ordinary boundaries; normalize only before integer-frame overflow.
    $limit = $this->animation->maximumElapsedSeconds;
    $delta = ($seconds > $limit ? fmod($seconds, $duration) : $seconds) - $this->elapsedCompensation;
    $elapsed = $this->elapsedSeconds + $delta;
    $this->elapsedCompensation = ($elapsed - $this->elapsedSeconds) - $delta;
    $this->elapsedSeconds = $elapsed;
    if ($elapsed > $limit) {
      $this->elapsedSeconds = fmod($elapsed, $duration);
      $this->elapsedCompensation = 0.0;
    }
  }

  public function getFrame(bool $reducedMotion = false): ?GraphicalSpriteDefinition
  {
    if ($this->released) { return null; }
    try {
      $frame = $this->animation->getFrame($this->assetRoot, $this->elapsedSeconds, $reducedMotion);
      $this->lastFailure = null;
      return $frame;
    } catch (InvalidArgumentException $error) {
      $failure = $error->getMessage();
      if ($failure !== $this->lastFailure) {
        Debug::warn($this->context . ' sprites2d unavailable; keeping terminal sprite: ' . $failure);
        $this->lastFailure = $failure;
      }
      return null;
    }
  }

  public function pause(): void { $this->paused = true; }
  public function resume(): void { if (!$this->released) { $this->paused = false; } }
  public function release(): void { $this->released = true; }
}
