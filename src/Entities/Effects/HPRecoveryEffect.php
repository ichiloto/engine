<?php

namespace Ichiloto\Engine\Entities\Effects;

use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

/**
 * The HP recovery effect class.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class HPRecoveryEffect extends BaseEffect
{
  /**
   * @inheritDoc
   */
  public function apply(CharacterInterface $target): void
  {
    if ($this->valueBasis === ValueBasis::PERCENTAGE) {
      $percentage = $this->value / 100;
      $target->stats->currentHp += $target->stats->totalHp * $percentage;
    }

    if ($this->valueBasis === ValueBasis::ACTUAL) {
      $target->stats->currentHp += $this->value;
    }
  }
}