<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Entities\Skills\SkillEffectContext;

/**
 * Represents the MP damage skill effect.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class MPDamageSkillEffect extends SkillEffect
{
  /**
   * @inheritDoc
   */
  public function apply(SkillEffectContext $context): void
  {
    if ($context->target->isKnockedOut) {
      return;
    }

    // Damage is floored at 1 so a resistant target is never restored by an attack.
    $context->target->stats->currentMp -= max(1, $this->getValue($context));
  }
}