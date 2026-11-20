<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasCompositeValues, CanvasGroupScale, CanvasValidation};
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
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
    $keys = EffectHeldSamples::compile($track['keyframes'], $length, static function (array $payload): array {
      CanvasCompositeValues::validateKeys($payload, ['x', 'y'], ['scale']);
      $limit = CanvasValidation::MAX_EXTENT;
      $sample = ['x' => CanvasCompositeValues::getNumber($payload['x'], -$limit, $limit),
        'y' => CanvasCompositeValues::getNumber($payload['y'], -$limit, $limit)];
      if (array_key_exists('scale', $payload)) {
        $sample['scale'] = (new CanvasGroupScale(
          CanvasCompositeValues::getNumber($payload['scale'], 0, CanvasGroupScale::MAX_FACTOR),
          new PresentationSpritePivot(.5, .5)))->factor;
      }
      return $sample;
    });
    return ['id' => $track['id'], 'type' => 'field-motion', 'presentation' => 'graphical', 'keyframes' => $keys];
  }
}
