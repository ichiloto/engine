<?php

namespace Ichiloto\Engine\Battle\Entry;

use InvalidArgumentException;

/** Which immutable entry roster an actor must occupy. */
enum BattleEntryActorPresence: string
{
  case ACTIVE = 'active';
  case RESERVE = 'reserve';
  case ANY = 'any';

  public static function require(mixed $value, string $source): self
  {
    if (! is_string($value)) {
      throw new InvalidArgumentException(sprintf(
        '%s field "presence" must be one of: %s.',
        $source,
        implode(', ', array_column(self::cases(), 'value')),
      ));
    }

    return self::tryFrom(trim($value)) ?? throw new InvalidArgumentException(sprintf(
      '%s field "presence" has unsupported value "%s"; expected one of: %s.',
      $source,
      $value,
      implode(', ', array_column(self::cases(), 'value')),
    ));
  }
}
