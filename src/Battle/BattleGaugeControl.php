<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/** Effects request timing changes from the engine that owns readiness. */
interface BattleGaugeControl
{
  public function resetBattlerGauge(CharacterInterface $battler): bool;
}
