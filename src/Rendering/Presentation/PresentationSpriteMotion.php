<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use InvalidArgumentException;

/**
 * How a retained field sprite reached its current cell: one step, presented
 * as a slide from the cell it last stood on over this many seconds.
 *
 * Presentation only. The sprite's cell is already committed; the renderer
 * may draw it partway along the step but never decides where it is.
 */
final readonly class PresentationSpriteMotion
{
  /** A slide longer than this is a pause, not a step. */
  public const float MAX_SECONDS = 60.0;

  public function __construct(public float $seconds)
  {
    if (!is_finite($seconds) || $seconds <= 0.0 || $seconds > self::MAX_SECONDS) {
      throw new InvalidArgumentException('A sprite step lasts more than zero and at most 60 seconds.');
    }
  }

  /** @return array{duration: float} */
  public function toArray(): array
  {
    return ['duration' => $this->seconds];
  }
}
