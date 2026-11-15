<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use InvalidArgumentException;

/** Authored pulse intensity; omitted values retain the legacy graphical strength. */
final class EffectFlashStrength
{
  public static function getStrength(array $payload): float
  {
    $value = array_key_exists('strength', $payload) ? $payload['strength'] : .22;
    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0 || $value > 1) {
      throw new InvalidArgumentException('Flash strength must be a finite number within 0..1.');
    }
    return (float)$value;
  }
}
