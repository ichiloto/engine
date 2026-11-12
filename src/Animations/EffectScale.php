<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations;

use InvalidArgumentException;

/**
 * How large an action plays its animation's target effect, as a multiple of
 * the size the effect timeline authors.
 *
 * One timeline serves every grade of a spell; each skill or item binding
 * states its own scale, so Freeze I and Blizzard share artwork and play at
 * different sizes. Graphical image tracks grow or shrink around their pivot
 * and ground attachment, with their cell offsets scaled alike. Terminal
 * glyphs keep their authored strike: a glyph cannot be resized without
 * distorting it.
 */
final class EffectScale
{
  public const float DEFAULT = 1.0;
  public const float MINIMUM = 0.25;
  public const float MAXIMUM = 4.0;

  /**
   * Reads an authored scale; an absent value plays the timeline's own size.
   *
   * @throws InvalidArgumentException When the value is not a number within the supported range.
   */
  public static function readValue(mixed $value, string $key = 'animationScale'): float
  {
    if ($value === null) {
      return self::DEFAULT;
    }
    if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)
      || $value < self::MINIMUM || $value > self::MAXIMUM) {
      throw new InvalidArgumentException(sprintf('%s must be a number from %s to %s.',
        $key, self::MINIMUM, self::MAXIMUM));
    }

    return (float) $value;
  }

  /** Whether a scale plays the timeline as authored, so records leave it out. */
  public static function playsAuthoredSize(float $scale): bool
  {
    return abs($scale - self::DEFAULT) < 1e-9;
  }
}
