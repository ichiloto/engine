<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues;
use InvalidArgumentException;

/** Explicit held drawings and clears covering one unchanged authored timeline. */
final class EffectReducedMotionSamples
{
  /** @return list<array{frame: int, duration: int, sourceFrame: ?int}> */
  public static function validate(mixed $data, int $length): array
  {
    $samples = CanvasCompositeValues::getList($data, 1, min(10000, $length));
    $end = 0;
    foreach ($samples as $sample) {
      if (!is_array($sample) || !array_key_exists('sourceFrame', $sample)) {
        throw new InvalidArgumentException('Reduced-motion samples require an explicit source frame or null clear.');
      }
      $interval = $sample;
      unset($interval['sourceFrame']);
      CanvasCompositeValues::validateKeys($interval, ['frame', 'duration']);
      if (!is_int($sample['frame']) || $sample['frame'] !== $end || !is_int($sample['duration'])
        || $sample['duration'] < 1 || $sample['duration'] > $length - $end
        || ($sample['sourceFrame'] !== null && (!is_int($sample['sourceFrame'])
          || $sample['sourceFrame'] < 0 || $sample['sourceFrame'] >= $length))) {
        throw new InvalidArgumentException('Reduced-motion samples require contiguous bounded holds or explicit null clears.');
      }
      $end += $sample['duration'];
    }
    if ($end !== $length) { throw new InvalidArgumentException('Reduced-motion samples must cover the complete authored timeline.'); }
    return $samples;
  }

  /** A validated plan selects a drawing without seeking or consuming any playback cues. */
  public static function getSampleFrame(array $samples, int $frame): ?int
  {
    foreach ($samples as $sample) {
      if ($frame < $sample['frame']) { break; }
      if ($frame < $sample['frame'] + $sample['duration']) { return $sample['sourceFrame']; }
    }
    return null;
  }
}
