<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities\Elements;

use Ichiloto\Engine\Battle\Resolution\ElementalAffinityResolver;
use Ichiloto\Engine\Battle\Resolution\ElementalOutcome;

/**
 * How a defender's elemental affinity reads to the player, from the same multiplier combat applies.
 * Resistances split into lesser and greater at GREATER_RESISTANCE_CEILING (half damage or less).
 */
enum ElementalAffinityTier: string
{
  public const float GREATER_RESISTANCE_CEILING = 0.5;

  case WEAKNESS = 'weakness';
  case LESSER_RESISTANCE = 'lesser_resistance';
  case GREATER_RESISTANCE = 'greater_resistance';
  case IMMUNITY = 'immunity';
  case ABSORPTION = 'absorption';

  /** Neutral affinities have no tier. */
  public static function classify(float $multiplier): ?self
  {
    return match (ElementalAffinityResolver::classify($multiplier)) {
      ElementalOutcome::WEAK => self::WEAKNESS,
      ElementalOutcome::RESIST => $multiplier <= self::GREATER_RESISTANCE_CEILING ? self::GREATER_RESISTANCE : self::LESSER_RESISTANCE,
      ElementalOutcome::NULL => self::IMMUNITY,
      ElementalOutcome::ABSORB => self::ABSORPTION,
      ElementalOutcome::NORMAL => null,
    };
  }

  /**
   * Every tier, each listing its element identities in the project's element order.
   *
   * @param array<string, float> $affinities Canonical identities to multipliers.
   * @return array<string, list<string>> Tier value to identities; tiers without elements stay present and empty.
   */
  public static function group(array $affinities): array
  {
    $groups = array_fill_keys(array_column(self::cases(), 'value'), []);
    $order = array_flip(array_map(strtolower(...), ElementRegistry::identities()));
    uksort($affinities, static fn(string $a, string $b): int
      => ($order[strtolower($a)] ?? PHP_INT_MAX) <=> ($order[strtolower($b)] ?? PHP_INT_MAX) ?: strcmp($a, $b));
    foreach ($affinities as $element => $multiplier) {
      $tier = self::classify((float)$multiplier);
      if ($tier !== null) { $groups[$tier->value][] = $element; }
    }
    return $groups;
  }
}
