<?php

namespace Ichiloto\Engine\Entities\EquipmentOptimization;

/** Process-wide project policy selected during game bootstrap. */
final class EquipmentOptimizationPolicyRegistry
{
  private static ?EquipmentOptimizationPolicyInterface $policy = null;

  public static function configure(?EquipmentOptimizationPolicyInterface $policy): void
  {
    self::$policy = $policy;
  }

  public static function current(): EquipmentOptimizationPolicyInterface
  {
    return self::$policy ?? new LegacyEqualWeightEquipmentOptimizationPolicy();
  }

  public static function reset(): void
  {
    self::$policy = null;
  }
}
