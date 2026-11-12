<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Insight;

use InvalidArgumentException;

/**
 * A fact about a battler that is hidden from the player until a reveal
 * effect uncovers it: its HP, its MP, its combat stats, or which element
 * strikes it hardest.
 *
 * @package Ichiloto\Engine\Battle\Insight
 */
enum RevealFact: string
{
  /** Current and maximum HP. */
  case HP = 'hp';
  /** Current and maximum MP. */
  case MP = 'mp';
  /** Attack, Defence, Magic Attack, Magic Defence, Speed, Grace and Evasion. */
  case STATS = 'stats';
  /** The strongest element against the battler, or that none beats ordinary damage. */
  case AFFINITIES = 'affinities';

  /** The Engine-owned knowledge observation a reveal of this fact records for the battler's kind. */
  public function getObservationId(): string
  {
    return 'reveal.' . $this->value;
  }

  /**
   * Reads an authored list of facts.
   *
   * @param mixed $facts The authored list.
   * @param string $path Where the list is authored, for messages.
   * @return list<self> The facts, each once, in authored order.
   * @throws InvalidArgumentException When the list is empty, not a list, or names an unknown or repeated fact.
   */
  public static function readList(mixed $facts, string $path = 'facts'): array
  {
    if (! is_array($facts) || $facts === [] || ! array_is_list($facts)) {
      throw new InvalidArgumentException(sprintf('%s must list the facts it reveals: %s.', $path, self::listValues()));
    }
    $read = [];
    foreach ($facts as $fact) {
      $case = is_string($fact) ? self::tryFrom(trim($fact)) : ($fact instanceof self ? $fact : null);
      if ($case === null) {
        throw new InvalidArgumentException(sprintf('%s names "%s", which is not one of %s.', $path,
          is_scalar($fact) ? strval($fact) : get_debug_type($fact), self::listValues()));
      }
      if (in_array($case, $read, true)) {
        throw new InvalidArgumentException(sprintf('%s names %s twice.', $path, $case->value));
      }
      $read[] = $case;
    }

    return $read;
  }

  /** Every fact's value, as authored lists name them. */
  public static function listValues(): string
  {
    return implode(', ', array_map(static fn(self $fact): string => $fact->value, self::cases()));
  }
}
