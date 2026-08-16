<?php

namespace Ichiloto\Engine\Progress\Knowledge;

use InvalidArgumentException;

/** Shared stable-ID validation for project definitions and save progress. */
final class KnowledgeIdentity
{
  public static function require(string $value, string $label = 'knowledge id'): string
  {
    $value = trim($value);

    if (! preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $value)) {
      throw new InvalidArgumentException(sprintf(
        '%s must be a lowercase stable ID containing only letters, numbers, dots, underscores, or hyphens.',
        ucfirst($label),
      ));
    }

    return $value;
  }
}
