<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

/**
 * Non-blocking, elapsed-time playback for one compiled summon cutscene.
 *
 * The session owns traversal state only. Rendering hosts may inspect any frame
 * without firing cues, while runtime advancement returns every crossed frame
 * and cue even when a single update spans several frames.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonPlaybackSession
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
    public readonly SummonCompiledCutscene $cutscene,
    ?bool $loop = null,
    ?float $speed = null,
  )
  {
    $playback = is_array($cutscene->defaults['playback'] ?? null)
      ? $cutscene->defaults['playback']
      : [];
    $this->totalFrames = max(1, intval($cutscene->defaults['lengthFrames'] ?? 1));
    $this->fps = max(1, $cutscene->fps);
    $this->effectiveSpeed = max(0.01, $speed ?? floatval($playback['defaultSpeed'] ?? 1.0));
    $this->isLooping = $loop ?? boolval($playback['loop'] ?? false);

    foreach ($cutscene->cueSchedule as $cue) {
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
  public function activeSegments(?int $frame = null): array
  {
    $frame ??= $this->currentFrame;

    return array_values(array_filter(
      $this->cutscene->playbackSegments,
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
  public function cuesAt(?int $frame = null): array
  {
    return $this->cuesByFrame[$frame ?? $this->currentFrame] ?? [];
  }

  public function update(float $elapsedSeconds): SummonPlaybackUpdate
  {
    if ($this->isPaused || $this->isCompleted || $elapsedSeconds <= 0.0) {
      return new SummonPlaybackUpdate();
    }

    $this->accumulatedSeconds += $elapsedSeconds;
    $crossedFrames = [];
    $crossedCues = $this->currentFrameCuesPending ? $this->cuesAt() : [];
    $this->currentFrameCuesPending = false;
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

      foreach ($this->cuesAt() as $cue) {
        $crossedCues[] = $cue;
      }
    }

    return new SummonPlaybackUpdate($crossedFrames, $crossedCues);
  }
}
