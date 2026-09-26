<?php

namespace Ichiloto\Engine\Rendering\Sprites;

use InvalidArgumentException;

/**
 * RPG Maker's walking frames, advanced by the character's own steps.
 *
 * Presentation-only: it never infers held keys or moves anything. Each step
 * advances one stride through the pattern 1, 2, 1, 0 around the standing
 * frame; with no step for one stride's duration the character stands again.
 */
final class CharacterWalkAnimation
{
  /** RPG Maker's frame order while walking; the first entry is the standing frame. */
  public const array PATTERNS = [1, 2, 1, 0];
  /** RPG Maker's default walking speed crosses one tile in 16 frames at 60 frames per second. */
  public const float STRIDE_SECONDS = 16 / 60;

  private int $stride = 0;
  private float $remaining = 0.0;

  public function step(): void
  {
    $this->stride = ($this->stride + 1) % count(self::PATTERNS);
    $this->remaining = self::STRIDE_SECONDS;
  }

  public function advance(float $seconds): void
  {
    if (!is_finite($seconds) || $seconds < 0.0) {
      throw new InvalidArgumentException('Sprite animation delta must be finite and nonnegative.');
    }
    $this->remaining -= $seconds;
    if ($this->remaining <= 0.0) {
      $this->stop();
    }
  }

  public function stop(): void
  {
    $this->stride = 0;
    $this->remaining = 0.0;
  }

  /** The frame (0 to 2) to draw now; 1 while standing. */
  public function getPattern(): int
  {
    return self::PATTERNS[$this->stride];
  }
}
