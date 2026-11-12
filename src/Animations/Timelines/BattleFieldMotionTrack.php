<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues;
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
    $keys = EffectMotionSamples::compile($track['keyframes'], $length);
    return ['id' => $track['id'], 'type' => 'field-motion', 'presentation' => 'graphical', 'keyframes' => $keys];
  }
}
