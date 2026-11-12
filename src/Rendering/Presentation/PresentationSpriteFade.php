<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use InvalidArgumentException;

/**
 * A field sprite's opacity, declared to the renderer, which eases to it at
 * display rate: from the opacity it draws the sprite at now to `opacity`,
 * linearly over `seconds` on its field clock. Zero seconds switches at
 * once. A sprite that declares no fade is drawn opaque.
 *
 * Presentation only, sent to a renderer that negotiated sprite_fade. The
 * game switches without easing under reduced motion.
 */
final readonly class PresentationSpriteFade
{
  public const float MAX_SECONDS = 10.0;

  public function __construct(public float $opacity, public float $seconds = 0.0)
  {
    if (!is_finite($opacity) || $opacity < 0.0 || $opacity > 1.0) {
      throw new InvalidArgumentException('A sprite fade opacity is 0..1.');
    }
    if (!is_finite($seconds) || $seconds < 0.0 || $seconds > self::MAX_SECONDS) {
      throw new InvalidArgumentException('A sprite fade lasts 0..10 seconds.');
    }
  }

  /** @return array{opacity: float, seconds: float} */
  public function toArray(): array
  {
    return ['opacity' => $this->opacity, 'seconds' => $this->seconds];
  }
}
