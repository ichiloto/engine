<?php

namespace Ichiloto\Engine\Battle\Resolution;

/** Injectable randomness shared by live combat and deterministic simulation. */
interface CombatRandomSource
{
  public function nextInt(int $minimum, int $maximum): int;
}
