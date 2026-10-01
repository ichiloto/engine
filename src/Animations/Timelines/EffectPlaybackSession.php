<?php

namespace Ichiloto\Engine\Animations\Timelines;

use InvalidArgumentException;

/**
 * Non-blocking, elapsed-time playback for one compiled effect timeline.
 *
 * The session owns traversal state only. Rendering hosts may inspect any frame
 * without firing cues, while runtime advancement returns every crossed frame
 * and cue even when a single update spans several frames.
 *
 * @package Ichiloto\Engine\Animations\Timelines
 */
class EffectPlaybackSession
{
  protected float $accumulatedSeconds = 0.0;
  protected bool $currentFrameCuesPending = true;

  /** @var array<int, array<int, array<string, mixed>>> */
  protected array $cuesByFrame = [];

  protected(set) int $currentFrame = 0;
  protected(set) bool $isPaused = false;
  protected(set) bool $isCompleted = false;
  protected(set) bool $isLooping;
  protected(set) int $traversal = 0;

  public readonly int $totalFrames;
  public readonly int $fps;
  public readonly float $effectiveSpeed;

  public float $secondsPerFrame {
    get => 1.0 / ($this->fps * $this->effectiveSpeed);
  }

  public function __construct(
    public readonly CompiledEffectTimeline $timeline,
    ?bool $loop = null,
    ?float $speed = null,
  )
  {
    if ($speed !== null && !is_finite($speed)) {
      throw new InvalidArgumentException('Effect playback speed must be finite.');
    }
    $playback = is_array($timeline->defaults['playback'] ?? null)
      ? $timeline->defaults['playback']
      : [];
    $this->totalFrames = max(1, intval($timeline->defaults['lengthFrames'] ?? 1));
    $this->fps = max(1, $timeline->fps);
    $effectiveSpeed = $speed ?? floatval($playback['defaultSpeed'] ?? 1.0);
    if (!is_finite($effectiveSpeed)) {
      throw new InvalidArgumentException('Effect playback speed must be finite.');
    }
    $this->effectiveSpeed = max(0.01, $effectiveSpeed);
    $this->isLooping = $loop ?? boolval($playback['loop'] ?? false);

    foreach ($timeline->cueSchedule as $cue) {
      $frame = intval($cue['frame'] ?? -1);

      if ($frame < 0 || $frame >= $this->totalFrames) {
        continue;
      }

      $this->cuesByFrame[$frame] ??= [];
      $this->cuesByFrame[$frame][] = $cue;
    }
  }

  public function pause(): void
  {
    $this->isPaused = true;
  }

  public function resume(): void
  {
    if (! $this->isCompleted) {
      $this->isPaused = false;
    }
  }

  public function restart(): void
  {
    $this->currentFrame = 0;
    $this->accumulatedSeconds = 0.0;
    $this->currentFrameCuesPending = true;
    $this->isPaused = false;
    $this->isCompleted = false;
    $this->traversal++;
  }

  public function seek(int $frame): void
  {
    $this->currentFrame = clamp($frame, 0, $this->totalFrames - 1);
    $this->accumulatedSeconds = 0.0;
    // Seeking and stepping are inspection operations. They reposition the
    // playhead without pretending that the destination frame was traversed.
    $this->currentFrameCuesPending = false;
    $this->isCompleted = false;
  }

  public function stepForward(): void
  {
    $this->seek(min($this->totalFrames - 1, $this->currentFrame + 1));
  }

  public function stepBackward(): void
  {
    $this->seek(max(0, $this->currentFrame - 1));
  }

  /** @return array<int, array<string, mixed>> */
  public function getActiveSegments(?int $frame = null): array
  {
    $frame ??= $this->currentFrame;

    return array_values(array_filter(
      $this->timeline->playbackSegments,
      static fn(array $segment): bool =>
        $frame >= intval($segment['startFrame'] ?? -1)
        && $frame <= intval($segment['endFrame'] ?? -1)
    ));
  }

  /**
   * Returns authored cues for inspection only. Calling this does not mark
   * them fired and does not alter traversal state.
   *
   * @return array<int, array<string, mixed>>
   */
  public function getCuesAt(?int $frame = null): array
  {
    return $this->cuesByFrame[$frame ?? $this->currentFrame] ?? [];
  }

  /** Consume entry cues once; inspection through getCuesAt remains side effect free. */
  public function takeCurrentFrameCues(): array
  {
    if (!$this->currentFrameCuesPending) { return []; }
    $this->currentFrameCuesPending = false;
    return $this->getCuesAt();
  }

  public function update(float $elapsedSeconds): EffectPlaybackUpdate
  {
    if (!is_finite($elapsedSeconds)) {
      throw new InvalidArgumentException('Effect elapsed time must be finite.');
    }
    if ($this->isPaused || $this->isCompleted || $elapsedSeconds <= 0.0) {
      return new EffectPlaybackUpdate();
    }

    $this->accumulatedSeconds += $elapsedSeconds;
    $crossedFrames = [];
    $crossedCues = $this->takeCurrentFrameCues();
    $frameDuration = $this->secondsPerFrame;

    while ($this->accumulatedSeconds + PHP_FLOAT_EPSILON >= $frameDuration) {
      $this->accumulatedSeconds = max(0.0, $this->accumulatedSeconds - $frameDuration);

      if ($this->currentFrame >= $this->totalFrames - 1) {
        if (! $this->isLooping) {
          $this->isCompleted = true;
          $this->accumulatedSeconds = 0.0;
          break;
        }

        $this->currentFrame = 0;
        $this->traversal++;
      } else {
        $this->currentFrame++;
      }

      $crossedFrames[] = $this->currentFrame;

      foreach ($this->getCuesAt() as $cue) {
        $crossedCues[] = $cue;
      }
    }

    return new EffectPlaybackUpdate($crossedFrames, $crossedCues);
  }
}
