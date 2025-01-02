<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Entities\Effects\SkillEffects\SkillEffect;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;

/**
 * Represents the MP recovery skill effect.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class MPRecoverySkillEffect extends SkillEffect
{
  /**
   * @inheritDoc
   */
  public function apply(SkillEffectContext $context): void
  {
    if ($context->target->isKnockedOut) {
      return;
    }

    $context->target->stats->currentMp += $this->getValue($context);
  }
}