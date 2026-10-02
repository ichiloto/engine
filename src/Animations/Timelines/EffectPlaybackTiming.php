<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use InvalidArgumentException;

/** Immutable authored pacing shared by standalone playback and composed timelines. */
final class EffectPlaybackTiming
{
  public readonly int $totalFrames;
  public readonly int $fps;
  public readonly float $effectiveSpeed;
  private readonly ?float $frameDurationSeconds;

  public float $secondsPerFrame { get => $this->frameDurationSeconds ?? 1.0 / ($this->fps * $this->effectiveSpeed); }
  public float $durationSeconds { get => $this->frameDurationSeconds === null
    ? $this->totalFrames / ($this->fps * $this->effectiveSpeed) : $this->totalFrames * $this->frameDurationSeconds; }

  /** Legacy consumers own an exact frame duration until their authored format migrates. */
  public function __construct(CompiledEffectTimeline $timeline, ?float $speed = null, ?float $secondsPerFrame = null)
  {
    if ($secondsPerFrame !== null && ($speed !== null || !is_finite($secondsPerFrame) || $secondsPerFrame <= 0)) {
      throw new InvalidArgumentException('An explicit effect frame duration must be finite, positive and independent of a speed override.');
    }
    $this->frameDurationSeconds = $secondsPerFrame;
    $playback = is_array($timeline->defaults['playback'] ?? null) ? $timeline->defaults['playback'] : [];
    $speed ??= floatval($playback['defaultSpeed'] ?? 1.0);
    if (!is_finite($speed)) {
      throw new InvalidArgumentException('Effect playback speed must be finite.');
    }
    $this->totalFrames = max(1, intval($timeline->defaults['lengthFrames'] ?? 1));
    $this->fps = max(1, $timeline->fps);
    $this->effectiveSpeed = $secondsPerFrame === null ? max(0.01, $speed) : 1.0 / ($this->fps * $secondsPerFrame);
    if (!is_finite($this->fps * $this->effectiveSpeed) || $this->effectiveSpeed <= 0) {
      throw new InvalidArgumentException('Effective effect playback rate must be finite.');
    }
  }

  /** Elapsed frames use the same rounding tolerance as runtime cue traversal. */
  public function getFrameCountAt(float $seconds): int
  {
    if (!is_finite($seconds)) {
      throw new InvalidArgumentException('Effect elapsed time must be finite.');
    }
    $frames = max(0.0, $seconds) / $this->secondsPerFrame;
    return (int)floor($frames + PHP_FLOAT_EPSILON * max(1, $frames) * 4);
  }

  /** Half-open boundaries never put a cue or new crop before its authored time. */
  public function getFrameBoundary(int $frame, int $outputFps): int
  {
    $frames = $this->frameDurationSeconds === null
      ? max(0, $frame) * max(1, $outputFps) / ($this->fps * $this->effectiveSpeed)
      : max(0, $frame) * max(1, $outputFps) * $this->frameDurationSeconds;
    return (int)ceil($frames - PHP_FLOAT_EPSILON * max(1, $frames) * 4);
  }
}
