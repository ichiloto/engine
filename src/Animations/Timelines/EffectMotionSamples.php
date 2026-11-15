<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;

/** Shared strict, source-preserving held offsets for graphical motion tracks. */
final class EffectMotionSamples
{
  public static function compile(mixed $frames, int $length): array
  {
    return EffectHeldSamples::compile($frames, $length, static function (array $payload): array {
      CanvasCompositeValues::validateKeys($payload, ['x', 'y']);
      $limit = CanvasValidation::MAX_EXTENT;
      return ['x' => CanvasCompositeValues::getNumber($payload['x'], -$limit, $limit),
        'y' => CanvasCompositeValues::getNumber($payload['y'], -$limit, $limit)];
    });
  }
}
