<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use InvalidArgumentException;

/**
 * What a directional battle track turns toward. A stroke turns toward its
 * recipient; a ward raised in front of a battler turns toward that battler's
 * opponents, wherever its caster stands.
 */
enum EffectFacingTarget: string
{
  case RECIPIENT = 'recipient';
  case OPPONENTS = 'opponents';

  public static function parse(mixed $value): self
  {
    return is_string($value) && ($target = self::tryFrom($value)) !== null ? $target
      : throw new InvalidArgumentException('Effect toward must be recipient or opponents.');
  }
}
