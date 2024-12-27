<?php

namespace Ichiloto\Engine\Entities\Effects;

use Ichiloto\Engine\Entities\Effects\BaseEffect;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as EffectTarget;

/**
 * The resurrection effect. This effect is used to bring a character back to life.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class ResurrectionEffect extends BaseEffect
{
  /**
   * @inheritDoc
   */
  public function apply(EffectTarget $target): void
  {
    if ($target->isKnockedOut) {
      if ($this->valueBasis === ValueBasis::PERCENTAGE) {
        $percentage = $this->value / 100;
        $target->stats->currentHp += $target->stats->totalHp * $percentage;
      }

      if ($this->valueBasis === ValueBasis::ACTUAL) {
        $target->stats->currentHp += $this->value;
      }
    }
  }
}