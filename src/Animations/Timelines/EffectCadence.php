<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use InvalidArgumentException;

enum EffectCadence: string
{
  case FIXED = 'fixed';
  case BATTLE_PHASE = 'battle_phase';

  public static function parse(mixed $value): self
  {
    return is_string($value) && ($cadence = self::tryFrom($value)) !== null ? $cadence
      : throw new InvalidArgumentException('Effect cadence must be fixed or battle_phase.');
  }
}
