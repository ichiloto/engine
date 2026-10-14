<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use InvalidArgumentException;

enum EffectImageAttachment: string
{
  case CENTER = 'center';
  case HEAD = 'head';
  case GROUND = 'ground';

  public static function parse(mixed $value): self
  {
    return is_string($value) && ($attachment = self::tryFrom($value)) !== null ? $attachment
      : throw new InvalidArgumentException('Effect image attachment must be center, head or ground.');
  }
}
