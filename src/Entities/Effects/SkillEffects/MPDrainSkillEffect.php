<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Entities\Effects\SkillEffects\SkillEffect;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;

/**
 * Represents the MP drain skill effect.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class MPDrainSkillEffect extends SkillEffect
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
    $context->target->stats->currentMp -= $value;
    $context->user->stats->currentMp += $value;
  }
}