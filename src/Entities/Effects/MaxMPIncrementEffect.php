<?php

namespace Ichiloto\Engine\Entities\Effects;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as EffectTarget;

/**
 * Represents an effect that increments the maximum MP of the target.
 *
 * @package Ichiloto\Engine\Effects
 */
class MaxMPIncrementEffect extends BaseEffect
{
  /**
   * @inheritDoc
   */
  public function apply(EffectTarget $target): void
  {
    $target->stats->totalMp += $this->value;
  }
}