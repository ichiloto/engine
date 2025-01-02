<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Entities\Effects\SkillEffects\SkillEffect;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;

class HPDrainSkillEffect extends SkillEffect
{
  /**
   * @inheritDoc
   */
  public function apply(SkillEffectContext $context): void
  {
    if ($context->target->isKnockedOut || $context->user->isKnockedOut) {
      return;
    }

    $value = $this->getValue($context);
    $context->target->stats->currentHp -= $value;
    $context->user->stats->currentHp += $value;
  }
}