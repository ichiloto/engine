<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Battle\Resolution\CombatResolutionRequest;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
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

    $result = $context->resolver->resolve(
      new CombatResolutionRequest(
        actionId: $context->actionId,
        executionId: $context->executionId,
        actor: $context->user,
        target: $context->target,
        rawMagnitude: max(0, $this->getValue($context)),
        kind: ResolutionKind::HEALING,
        baseAccuracy: null,
        criticalEligible: false,
        guardEligible: false,
        minimumDamage: 0,
      ),
      $context->random,
    );
    $context->recordResult($result);
  }
}
