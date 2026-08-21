<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Battle\Resolution\CombatResolutionRequest;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Effects\SkillEffects\SkillEffect;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;

class HPDrainSkillEffect extends SkillEffect
{
  public function repeatsWithInvocation(): bool
  {
    return true;
  }

  /**
   * @inheritDoc
   */
  public function apply(SkillEffectContext $context): void
  {
    if ($context->target->isKnockedOut || $context->user->isKnockedOut) {
      return;
    }

    $damage = $context->resolver->resolve(
      new CombatResolutionRequest(
        actionId: $context->actionId,
        executionId: $context->executionId,
        actor: $context->user,
        target: $context->target,
        rawMagnitude: max(0, $this->getValue($context)),
        kind: $context->damageKind,
        baseAccuracy: $context->baseAccuracy,
        hitRollOverride: $context->hitRollOverride(),
        criticalRollOverride: $context->criticalRollOverride(),
      ),
      $context->random,
    );
    $context->recordResult($damage);

    if ($damage->actualHpLost < 1) {
      return;
    }

    $healing = $context->resolver->resolve(
      new CombatResolutionRequest(
        actionId: $context->actionId . '.drain',
        executionId: $context->executionId,
        actor: $context->user,
        target: $context->user,
        rawMagnitude: $damage->actualHpLost,
        kind: ResolutionKind::HEALING,
        baseAccuracy: null,
        criticalEligible: false,
        guardEligible: false,
        minimumDamage: 0,
      ),
      $context->random,
    );
    $context->recordResult($healing);
  }
}
