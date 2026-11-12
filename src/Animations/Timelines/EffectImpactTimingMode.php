<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

enum EffectImpactTimingMode: string
{
  case END = 'end';
  case FRAME = 'frame';
  case CUE = 'cue';
  case ARENA = 'arena';

  public function usesFrame(): bool
  {
    return $this === self::FRAME || $this === self::ARENA;
  }
}
