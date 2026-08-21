<?php

namespace Ichiloto\Engine\Battle\Resolution;

final class NativeCombatRandomSource implements CombatRandomSource
{
  public function nextInt(int $minimum, int $maximum): int
  {
    return random_int($minimum, $maximum);
  }
}
