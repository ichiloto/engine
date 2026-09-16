<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use InvalidArgumentException;

/** One opening decision, shared by traditional and active-time battles. */
enum EncounterAdvantage: string
{
  case NORMAL = 'normal';
  case PARTY = 'party';
  case TROOP = 'troop';

  public static function fromSetting(mixed $value): self
  {
    if ($value === null) {
      return self::NORMAL;
    }

    return (is_string($value) ? self::tryFrom($value) : null)
      ?? throw new InvalidArgumentException('firstStrike must be normal, party, troop, or null.');
  }

  public static function forBattle(
    array $settings,
    CombatRandomSource $random,
    int $preemptiveChance = 8,
    int $ambushChance = 6,
  ): self
  {
    // An explicit normal/null opening must not fall through to another roll.
    if (array_key_exists('firstStrike', $settings)) {
      return self::fromSetting($settings['firstStrike']);
    }

    $preemptiveChance = intval($settings['opening']['preemptiveChancePercent'] ?? $preemptiveChance);
    $ambushChance = intval($settings['opening']['ambushChancePercent'] ?? $ambushChance);

    if ($preemptiveChance < 0 || $ambushChance < 0 || $preemptiveChance + $ambushChance > 100) {
      throw new InvalidArgumentException('Opening advantage chances must be non-negative and total at most 100.');
    }

    $roll = $random->nextInt(1, 100);

    return match (true) {
      $roll <= $preemptiveChance => self::PARTY,
      $roll <= $preemptiveChance + $ambushChance => self::TROOP,
      default => self::NORMAL,
    };
  }
}
