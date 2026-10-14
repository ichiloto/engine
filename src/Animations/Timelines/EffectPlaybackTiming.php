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

  /** The owning battle phase supplies a budget; legacy hosts may supply an exact frame duration. */
  public function __construct(CompiledEffectTimeline $timeline, ?float $speed = null, ?float $secondsPerFrame = null,
    ?float $phaseDurationSeconds = null)
  {
    if ($secondsPerFrame !== null && ($speed !== null || !is_finite($secondsPerFrame) || $secondsPerFrame <= 0)) {
      throw new InvalidArgumentException('An explicit effect frame duration must be finite, positive and independent of a speed override.');
    }
    $playback = is_array($timeline->defaults['playback'] ?? null) ? $timeline->defaults['playback'] : [];
    $speed ??= floatval($playback['defaultSpeed'] ?? 1.0);
    if (!is_finite($speed)) {
      throw new InvalidArgumentException('Effect playback speed must be finite.');
    }
    $this->totalFrames = max(1, intval($timeline->defaults['lengthFrames'] ?? 1));
    $this->fps = $timeline->cadence === EffectCadence::BATTLE_PHASE ? 120 : max(1, $timeline->fps);
    if ($timeline->cadence === EffectCadence::BATTLE_PHASE) {
      if ($phaseDurationSeconds === null || !is_finite($phaseDurationSeconds) || $phaseDurationSeconds <= 0
        || $secondsPerFrame !== null) {
        throw new InvalidArgumentException('Battle-phase cadence requires a finite positive phase duration, without an exact-frame override.');
      }
      $secondsPerFrame = $phaseDurationSeconds / $this->totalFrames / max(.01, $speed);
    } elseif ($phaseDurationSeconds !== null) {
      throw new InvalidArgumentException('A phase duration cannot override fixed effect cadence.');
    }
    $this->frameDurationSeconds = $secondsPerFrame;
    $this->effectiveSpeed = $secondsPerFrame === null ? max(0.01, $speed) : 1.0 / ($this->fps * $secondsPerFrame);
    if (!is_finite($this->fps * $this->effectiveSpeed) || $this->effectiveSpeed <= 0) {
      throw new InvalidArgumentException('Effective effect playback rate must be finite.');
    }
  }

  public static function createForBattlePhase(CompiledEffectTimeline $timeline, float $durationSeconds): self
  {
    if ($timeline->cadence === EffectCadence::FIXED) { return new self($timeline); }
    if (!is_finite($durationSeconds) || $durationSeconds < 0) {
      throw new InvalidArgumentException('Battle phase duration must be finite and non-negative.');
    }
    // Preserve the compatibility beat's existing minimum, even at zero pacing.
    return new self($timeline, phaseDurationSeconds: max(.01, $durationSeconds));
  }

  /** A presentation-only consumer supplies its existing, finite lifetime. */
  public static function createForDuration(CompiledEffectTimeline $timeline, float $durationSeconds): self
  {
    if ($timeline->cadence !== EffectCadence::FIXED || !is_finite($durationSeconds) || $durationSeconds <= 0) {
      throw new InvalidArgumentException('Consumer presentation duration requires a fixed timeline and finite positive seconds.');
    }
    return new self($timeline, secondsPerFrame: $durationSeconds / max(1, intval($timeline->defaults['lengthFrames'] ?? 1)));
  }

  /** Elapsed frames use the same rounding tolerance as runtime cue traversal. */
  public function getFrameCountAt(float $seconds): int
  {
    return self::getFrameCountForElapsed($seconds, $this->secondsPerFrame);
  }

  /** Poses and timeline sessions share half-open boundaries without sharing traversal state. */
  public static function getFrameCountForElapsed(float $seconds, float $secondsPerFrame): int
  {
    if (!is_finite($seconds)) {
      throw new InvalidArgumentException('Effect elapsed time must be finite.');
    }
    if (!is_finite($secondsPerFrame) || $secondsPerFrame <= 0) {
      throw new InvalidArgumentException('Frame duration must be finite and positive.');
    }
    $frames = max(0.0, $seconds) / $secondsPerFrame;
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
