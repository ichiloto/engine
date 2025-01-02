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

    $context->target->stats->currentMp -= $this->getValue($context);
  }
}