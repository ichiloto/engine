<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Entities\Character;

/**
 * What a level gained does to a character's current HP and MP. One project
 * rule covers both resources; every mode keeps them within the new maxima.
 */
enum LevelUpRecovery: string
{
  /** Current HP and MP refill to the new maxima. A knocked-out member is restored with them. */
  case FULL = 'full';
  /** Current HP and MP each gain what their maximum gained. A knocked-out member keeps 0 HP. */
  case INCREASE = 'increase';
  /** Current HP and MP stay as they were; only the maxima rise. */
  case NONE = 'none';

  /**
   * Applies this rule after an award raised the character's level, given the
   * maxima they had before it.
   */
  public function recover(Character $character, int $previousTotalHp, int $previousTotalMp): void
  {
    match ($this) {
      self::FULL => $character->restoreVitals(),
      self::INCREASE => self::addIncrease($character, $previousTotalHp, $previousTotalMp),
      self::NONE => null,
    };
  }

  private static function addIncrease(Character $character, int $previousTotalHp, int $previousTotalMp): void
  {
    $stats = $character->stats;
    // Adding to 0 HP would revive by levelling, which this rule never does.
    if ($character->isConscious) {
      $stats->currentHp = min($stats->totalHp, $stats->currentHp + max(0, $stats->totalHp - $previousTotalHp));
    }
    $stats->currentMp = min($stats->totalMp, $stats->currentMp + max(0, $stats->totalMp - $previousTotalMp));
  }
}
