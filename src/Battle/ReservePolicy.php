<?php

namespace Ichiloto\Engine\Battle;

use InvalidArgumentException;

/** Reserve replacement is an encounter choice, never a party or renderer default. */
enum ReservePolicy: string
{
  case NONE = 'none';
  case REPLACE_AFTER_WIPEOUT = 'replace_after_wipeout';

  public static function resolve(mixed $value): self
  {
    if ($value === null) { return self::NONE; }
    if ($value instanceof self) { return $value; }
    if (is_string($value) && ($policy = self::tryFrom($value)) !== null) { return $policy; }
    throw new InvalidArgumentException('Battle reservePolicy must be "none" or "replace_after_wipeout".');
  }
}
