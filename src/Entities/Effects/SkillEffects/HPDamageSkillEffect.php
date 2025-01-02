<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Entities\Skills\SkillEffectContext;

/**
 * Represents the HP damage skill effect.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class HPDamageSkillEffect extends SkillEffect
{
  public function apply(SkillEffectContext $context): void
  {
    if ($context->target->isKnockedOut) {
      return;
    }

    $context->target->stats->currentHp -= $this->getValue($context);
  }
}