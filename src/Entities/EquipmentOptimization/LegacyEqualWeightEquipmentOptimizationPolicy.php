<?php

namespace Ichiloto\Engine\Entities\EquipmentOptimization;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\EquipmentSlot;
use Ichiloto\Engine\Entities\Inventory\Equipment;

/**
 * Explicit compatibility policy for projects that have not declared their
 * own optimization rules. It preserves the historical equal-weight sum.
 */
final class LegacyEqualWeightEquipmentOptimizationPolicy implements EquipmentOptimizationPolicyInterface
{
  public function score(
    Character $character,
    EquipmentSlot $slot,
    Equipment $equipment,
  ): EquipmentOptimizationScore
  {
    return new EquipmentOptimizationScore($equipment->rating, ['legacyEqualWeight' => $equipment->rating]);
  }
}
