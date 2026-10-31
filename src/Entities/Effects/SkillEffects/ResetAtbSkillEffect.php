<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Entities\Skills\SkillEffectContext;

/** Resets a surviving target's active-time gauge through its current battle engine. */
final class ResetAtbSkillEffect extends SkillEffect
{
  public function __construct() { parent::__construct('0'); }

  public function apply(SkillEffectContext $context): void
  {
    foreach (is_array($context->target) ? $context->target : [$context->target] as $target) {
      $landed = $context->results() === [] || $context->hadSuccessfulResolution();
      $context->recordSecondaryOutcome('atbReset', 'gauge', $target->isFighting && $landed
        && ($context->gauges?->resetBattlerGauge($target) ?? false));
    }
  }
}
