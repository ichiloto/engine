<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Entities\Effects\SkillEffects\SkillEffect;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;

/**
 * Represents the HP recover skill effect.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class HPRecoverSkillEffect extends SkillEffect
{
  /**
   * @inheritDoc
   */
  public function apply(SkillEffectContext $context): void
  {
    if ($context->target->isKnockedOut) {
      return;
    }

    $context->target->stats->currentHp += $this->getValue($context);
  }
}