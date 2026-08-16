<?php

namespace Ichiloto\Engine\Entities\EquipmentOptimization;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\EquipmentSlot;
use Ichiloto\Engine\Entities\Inventory\Equipment;

/** Project-declared equipment evaluation boundary. */
interface EquipmentOptimizationPolicyInterface
{
  /**
   * Returns a score for one legal candidate, or null when project policy
   * excludes the candidate from automatic selection.
   */
  public function score(
    Character $character,
    EquipmentSlot $slot,
    Equipment $equipment,
  ): ?EquipmentOptimizationScore;
}
