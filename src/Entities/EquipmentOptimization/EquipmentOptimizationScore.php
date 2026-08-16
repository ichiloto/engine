<?php

namespace Ichiloto\Engine\Entities\EquipmentOptimization;

/** One deterministic policy result with inspectable component scores. */
final readonly class EquipmentOptimizationScore
{
  /** @param array<string, int> $components */
  public function __construct(
    public int $value,
    public array $components = [],
  )
  {
  }
}
