<?php

namespace Ichiloto\Engine\Battle;

use InvalidArgumentException;

/** Project-neutral classification authored for one battle. */
enum BattleClassification: string
{
  case ORDINARY = 'ordinary';
  case BOSS = 'boss';

  /**
   * Resolves authored data. Omission preserves historical ordinary battles.
   */
  public static function resolve(mixed $value, string $source = 'battle data'): self
  {
    if ($value === null) {
      return self::ORDINARY;
    }

    if (! is_string($value) || trim($value) === '') {
      throw new InvalidArgumentException(sprintf(
        '%s field "classification" must be one of: %s.',
        $source,
        implode(', ', array_column(self::cases(), 'value')),
      ));
    }

    return self::tryFrom(trim($value)) ?? throw new InvalidArgumentException(sprintf(
      '%s field "classification" has unsupported value "%s"; expected one of: %s.',
      $source,
      $value,
      implode(', ', array_column(self::cases(), 'value')),
    ));
  }
}
