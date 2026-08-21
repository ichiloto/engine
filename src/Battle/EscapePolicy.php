<?php

namespace Ichiloto\Engine\Battle;

use InvalidArgumentException;

/**
 * Controls whether the party may retreat from one battle.
 */
enum EscapePolicy: string
{
  case ALLOWED = 'allowed';
  case FORBIDDEN = 'forbidden';

  /**
   * Resolves an authored value without silently opening malformed battles.
   */
  public static function resolve(mixed $value, self $default = self::ALLOWED): self
  {
    if ($value === null) {
      return $default;
    }

    if ($value instanceof self) {
      return $value;
    }

    if (! is_string($value) || trim($value) === '') {
      throw new InvalidArgumentException('Battle escape policy must be "allowed" or "forbidden".');
    }

    return self::tryFrom(strtolower(trim($value)))
      ?? throw new InvalidArgumentException(sprintf('Unsupported battle escape policy "%s".', $value));
  }
}
