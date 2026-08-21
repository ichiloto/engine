<?php

namespace Ichiloto\Engine\Entities\Stats;

/** One generic arithmetic boundary for persistent stat-layer resolution. */
final class StatResolver
{
  public static function resolve(
    StatKey $stat,
    int $natural,
    int $actorNatural,
    int $permanent,
    int $equipment,
    EntityStatCapPolicy $caps,
    int $temporary = 0,
  ): StatResolution
  {
    return new StatResolution(
      $stat,
      $natural,
      $actorNatural,
      $permanent,
      $equipment,
      $temporary,
      $caps->capFor($stat),
    );
  }
}
