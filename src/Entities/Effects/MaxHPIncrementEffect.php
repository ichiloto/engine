<?php

namespace Ichiloto\Engine\Entities\Effects;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as EffectTarget;

/**
 * Represents an effect that increments the maximum MP of the target.
 *
 * @package Ichiloto\Engine\Effects
 */
class MaxHPIncrementEffect extends BaseEffect
{
  /**
   * @inheritDoc
   */
  public function apply(EffectTarget $target): void
  {
    $target->stats->totalHp += $this->value;
  }
}