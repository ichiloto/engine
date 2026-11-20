<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues;
use InvalidArgumentException;

/** The authored clock owns strict held intervals; each consumer validates its payload. */
final class EffectHeldSamples
{
  /** @param callable(array): array $compilePayload */
  public static function compile(mixed $frames, int $length, callable $compilePayload): array
  {
    $frames = CanvasCompositeValues::getList($frames, 1, min(10000, $length));
    $keys = []; $end = 0;
    foreach ($frames as $key) {
      if (!is_array($key)) { throw new InvalidArgumentException('Held samples require descriptors.'); }
      CanvasCompositeValues::validateKeys($key, ['frame', 'payload'], ['duration', 'visible', 'zIndex']);
      $start = $key['frame']; $duration = $key['duration'] ?? 1;
      if (!is_int($start) || !is_int($duration) || $start < $end || $start >= $length
        || $duration < 1 || $duration > $length - $start || !is_array($key['payload'])
        || (array_key_exists('visible', $key) && !is_bool($key['visible']))
        || (array_key_exists('zIndex', $key) && $key['zIndex'] !== 0)) {
        throw new InvalidArgumentException('Held samples require ordered, nonoverlapping frame intervals and explicit payloads.');
      }
      $keys[] = ['frame' => $start, 'duration' => $duration, 'visible' => $key['visible'] ?? true,
        'payload' => $compilePayload($key['payload'])];
      $end = $start + $duration;
    }
    return $keys;
  }
}
