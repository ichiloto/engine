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

    // Damage is floored at 1 so a high-defence target is never healed by an attack.
    $context->target->stats->currentHp -= max(1, $this->getValue($context));
  }
}