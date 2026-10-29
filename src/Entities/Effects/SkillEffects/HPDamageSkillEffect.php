<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Battle\Resolution\CombatResolutionRequest;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;

/**
 * Represents the HP damage skill effect.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
class HPDamageSkillEffect extends SkillEffect
{
  /** Historical serialized damage effects retain normal Guard/protective-state behavior. */
  protected(set) bool $ignoresProtection = false;
  public function repeatsWithInvocation(): bool
  {
    return true;
  }

  public function __construct(
    string $formula,
    ?string $element = null,
    float $variance = 0.2,
    bool $isCriticalHit = false,
    protected(set) ?ResolutionKind $resolutionKind = null,
    bool $ignoresProtection = false,
  )
  {
    parent::__construct($formula, $element, $variance, $isCriticalHit);
    $this->ignoresProtection = $ignoresProtection;
  }

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
        kind: $this->resolutionKind ?? $context->damageKind,
        element: $this->element,
        baseAccuracy: $context->baseAccuracy,
        criticalEligible: true,
        guaranteedCritical: $this->isCriticalHit,
        guardEligible: !$this->ignoresProtection,
        hitRollOverride: $context->hitRollOverride(),
        criticalRollOverride: $context->criticalRollOverride(),
      ),
      $context->random,
    );
    $context->recordResult($result);
  }
}
