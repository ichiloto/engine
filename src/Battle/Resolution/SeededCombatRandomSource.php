<?php

namespace Ichiloto\Engine\Battle\Resolution;

/** Small deterministic generator for tests, previews and repeatable simulations. */
final class SeededCombatRandomSource implements CombatRandomSource
{
  private int $state;

  public function __construct(public readonly int $seed)
  {
    $this->state = $seed & 0x7fffffff;
  }

  public function nextInt(int $minimum, int $maximum): int
  {
    if ($minimum > $maximum) {
      [$minimum, $maximum] = [$maximum, $minimum];
    }

    $this->state = (int) (($this->state * 1_103_515_245 + 12_345) & 0x7fffffff);
    $range = $maximum - $minimum + 1;

    return $minimum + ($this->state % $range);
  }
}
