<?php

namespace Ichiloto\Engine\Entities\Enemies;

use InvalidArgumentException;

/**
 * Where an enemy lives and so where it stands in a graphical battle: on
 * land, in water, or in the air over either. An arena marks which parts of
 * its picture are land and water ({@see \Ichiloto\Engine\Battle\Presentation\BattleFooting}),
 * so a land beast on a lake arena stands on the shore and a water creature
 * stands in the water. Authored per enemy; combat never reads it.
 */
enum EnemyHabitat: string
{
  case LAND = 'land';
  case WATER = 'water';
  case AIR = 'air';

  /**
   * Resolves authored data. Omission is land, as every enemy stood before habitats.
   */
  public static function resolve(mixed $value, string $source = 'enemy data'): self
  {
    if ($value === null) {
      return self::LAND;
    }

    return (is_string($value) ? self::tryFrom($value) : null) ?? throw new InvalidArgumentException(sprintf(
      '%s field "habitat" must be one of: %s.',
      $source,
      implode(', ', array_column(self::cases(), 'value')),
    ));
  }
}
