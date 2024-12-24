<?php

namespace Ichiloto\Engine\Entities\Effects;

use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as EffectTarget;

/**
 * The HP recovery effect class.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class MPRecoveryEffect extends BaseEffect
{
  /**
   * @inheritDoc
   */
  public function apply(EffectTarget $target): void
  {
    if ($this->valueBasis === ValueBasis::PERCENTAGE) {
      $percentage = $this->value / 100;
      $target->stats->currentMp += $target->stats->totalMp * $percentage;
    }

    if ($this->valueBasis === ValueBasis::ACTUAL) {
      $target->stats->currentMp += $this->value;
    }
  }
}