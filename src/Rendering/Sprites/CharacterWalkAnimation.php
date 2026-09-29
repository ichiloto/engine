<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\FieldMetric;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteMotion;
use Ichiloto\Engine\UI\Accessibility;
use InvalidArgumentException;

/**
 * RPG Maker's walking presentation, advanced by the distance a character's
 * own steps travel.
 *
 * Presentation-only: it never infers held keys or moves anything. Walking
 * cycles the pattern 1, 2, 1, 0 around the standing frame, one pattern per
 * PATTERN_PIXELS of field travel, so every axis animates at one pace.
 * A walk begins on its first stride, so even a single step shows one. Steps that follow each other keep the cycle going; once travel stops
 * for STOP_SECONDS the character stands again.
 *
 * It also remembers the latest step, so the renderer can slide the sprite
 * from the cell it left while the character still stands where that step
 * ended. Reduced motion shows the standing frame and no slide.
 */
final class CharacterWalkAnimation
{
  /** RPG Maker's frame order while walking; the first entry is the standing frame. */
  public const array PATTERNS = [1, 2, 1, 0];
  /** RPG Maker changes pattern every 10 frames of a 3-pixel-per-frame walk: 30 field pixels. */
  public const float PATTERN_PIXELS = 30.0;
  /** RPG Maker returns a stopped walker to its standing frame on its next 15-frame animation tick. */
  public const float STOP_SECONDS = 15 / 60;

  private bool $walking = false;
  /** Field pixels along the pattern cycle. */
  private float $travelled = 0.0;
  private float $remainingPixels = 0.0;
  private float $pixelsPerSecond = 0.0;
  private float $stoppedFor = 0.0;
  private ?CharacterStep $lastStep = null;

  public function __construct(private readonly FieldMetric $metric = new FieldMetric())
  {
  }

  public function step(CharacterStep $step): void
  {
    $pixels = $this->metric->getDistance($step->getDisplacement());
    if (!$this->walking) {
      $this->walking = true;
      $this->travelled = self::PATTERN_PIXELS;
    }
    // A step that starts before the previous one finished completes it now.
    $this->travelled += $this->remainingPixels;
    $this->remainingPixels = $pixels;
    $this->pixelsPerSecond = $step->seconds > 0.0 ? $pixels / $step->seconds : 0.0;
    if ($step->seconds <= 0.0) {
      $this->travelled += $pixels;
      $this->remainingPixels = 0.0;
    }
    $this->travelled = fmod($this->travelled, self::PATTERN_PIXELS * count(self::PATTERNS));
    $this->stoppedFor = 0.0;
    $this->lastStep = $step;
  }

  /**
   * Advance one stride for a step whose cells are unknown, without a slide:
   * a walk begins on its first stride and each later call moves one pattern on.
   */
  public function stride(): void
  {
    $this->travelled = $this->walking ? $this->travelled + $this->remainingPixels + self::PATTERN_PIXELS : self::PATTERN_PIXELS;
    $this->travelled = fmod($this->travelled, self::PATTERN_PIXELS * count(self::PATTERNS));
    $this->walking = true;
    $this->remainingPixels = $this->stoppedFor = 0.0;
    $this->lastStep = null;
  }

  public function advance(float $seconds): void
  {
    if (!is_finite($seconds) || $seconds < 0.0) {
      throw new InvalidArgumentException('Sprite animation delta must be finite and nonnegative.');
    }
    if (!$this->walking) {
      return;
    }
    if ($this->remainingPixels > 0.0) {
      $travel = min($this->remainingPixels, $this->pixelsPerSecond * $seconds);
      $this->travelled = fmod($this->travelled + $travel, self::PATTERN_PIXELS * count(self::PATTERNS));
      $this->remainingPixels -= $travel;
      $seconds -= $travel / $this->pixelsPerSecond;
      if ($this->remainingPixels > 1e-9) {
        return;
      }
      $this->remainingPixels = 0.0;
    }
    $this->stoppedFor += max(0.0, $seconds);
    if ($this->stoppedFor >= self::STOP_SECONDS) {
      $this->walking = false;
      $this->travelled = 0.0;
      $this->stoppedFor = 0.0;
    }
  }

  /** Stand at once: facing, interaction, suspension and blocked movement. The latest step is forgotten. */
  public function stop(): void
  {
    $this->walking = false;
    $this->travelled = $this->remainingPixels = $this->stoppedFor = 0.0;
    $this->lastStep = null;
  }

  /** The frame (0 to 2) to draw now; 1 while standing. */
  public function getPattern(): int
  {
    if (!$this->walking || Accessibility::prefersReducedMotion()) {
      return self::PATTERNS[0];
    }
    return self::PATTERNS[(int)floor($this->travelled / self::PATTERN_PIXELS) % count(self::PATTERNS)];
  }

  /**
   * The slide that presents the latest step, while the character still
   * stands on the cell that step reached. Any other placement (a transfer,
   * a restored cinematic transform) shows no slide.
   */
  public function getMotion(Vector2 $position): ?PresentationSpriteMotion
  {
    $step = $this->lastStep;
    if ($step === null || $step->seconds <= 0.0 || Accessibility::prefersReducedMotion()
      || $position->x != $step->to->x || $position->y != $step->to->y) {
      return null;
    }
    return new PresentationSpriteMotion(min($step->seconds, PresentationSpriteMotion::MAX_SECONDS));
  }
}
