<?php

namespace Ichiloto\Engine\Entities\Effects;

use Ichiloto\Engine\Entities\Effects\BaseEffect;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as EffectTarget;

/**
 * Represents the HP damage effect.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class HPDamageEffect extends BaseEffect
{
  /**
   * @inheritDoc
   */
  public function apply(EffectTarget $target): void
  {
    if ( $target->isKnockedOut ) {
      return;
    }

    if ($this->valueBasis === ValueBasis::PERCENTAGE) {
      $percentage = $this->value / 100;
      $target->stats->currentHp -= $target->stats->totalHp * $percentage;
    }

    if ($this->valueBasis === ValueBasis::ACTUAL) {
      $target->stats->currentHp -= $this->value;
    }
  }
}