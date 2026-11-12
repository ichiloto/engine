<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use InvalidArgumentException;

/** Explicit held samples in logical drawing pixels, independent of battle outcomes. */
final class BattleFieldMotionTrack
{
  public static function compile(array $track, int $length, string $playback, EffectCadence $cadence): array
  {
    CanvasCompositeValues::validateKeys($track, ['id', 'type', 'keyframes'], ['presentation']);
    if ($playback !== 'once' || $cadence !== EffectCadence::FIXED) {
      throw new InvalidArgumentException('Field motion requires a fixed, once-playing battle timeline.');
    }
    $frames = CanvasCompositeValues::getList($track['keyframes'], 1, min(10000, $length));
    $keys = [];
    $end = 0;
    foreach ($frames as $key) {
      CanvasCompositeValues::validateKeys($key, ['frame', 'payload'], ['duration', 'visible', 'zIndex']);
      $start = $key['frame'];
      $duration = $key['duration'] ?? 1;
      if (!is_int($start) || !is_int($duration) || $start < $end || $start >= $length
        || $duration < 1 || $duration > $length - $start
        || (array_key_exists('visible', $key) && !is_bool($key['visible']))
        || (array_key_exists('zIndex', $key) && $key['zIndex'] !== 0)) {
        throw new InvalidArgumentException('Field motion samples require ordered, nonoverlapping frame intervals.');
      }
      CanvasCompositeValues::validateKeys($key['payload'], ['x', 'y']);
      $limit = CanvasValidation::MAX_EXTENT;
      $keys[] = ['frame' => $start, 'duration' => $duration, 'visible' => $key['visible'] ?? true,
        'payload' => ['x' => CanvasCompositeValues::getNumber($key['payload']['x'], -$limit, $limit),
          'y' => CanvasCompositeValues::getNumber($key['payload']['y'], -$limit, $limit)]];
      $end = $start + $duration;
    }
    return ['id' => $track['id'], 'type' => 'field-motion', 'presentation' => 'graphical', 'keyframes' => $keys];
  }
}
