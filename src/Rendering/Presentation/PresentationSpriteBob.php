<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use InvalidArgumentException;

/**
 * A field sprite's gentle rise and fall, declared once and drawn by the
 * renderer at display rate: up to `height` logical pixels above its lift and
 * back over `seconds`, as height * (1 - cos(2 pi t / seconds)) / 2 on the
 * renderer's field clock. Every bob of the same period rises and falls
 * together, so a mark and its plate never part.
 *
 * Presentation only, sent to a renderer that negotiated sprite_bob. The game
 * leaves it off under reduced motion.
 */
final readonly class PresentationSpriteBob
{
  public const int MAX_HEIGHT = 256;
  public const float MIN_SECONDS = 0.1;
  public const float MAX_SECONDS = 60.0;

  public function __construct(public int $height, public float $seconds)
  {
    if ($height < 1 || $height > self::MAX_HEIGHT) {
      throw new InvalidArgumentException('A sprite bob rises 1..256 logical pixels.');
    }
    if (!is_finite($seconds) || $seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
      throw new InvalidArgumentException('A sprite bob lasts 0.1..60 seconds.');
    }
  }

  /** How far the bob raises its sprite at a time on the shared clock, in logical pixels. */
  public function getOffset(float $seconds): float
  {
    return $this->height * (1 - cos(2 * M_PI * fmod(max(0.0, $seconds), $this->seconds) / $this->seconds)) / 2;
  }

  /** @return array{height: int, seconds: float} */
  public function toArray(): array
  {
    return ['height' => $this->height, 'seconds' => $this->seconds];
  }
}
