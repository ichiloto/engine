<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Battle\ElementalDamage;
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
    $damage = max(1, $this->getValue($context));

    if ($context->criticalHit || $this->isCriticalHit) {
      $damage = intval(round($damage * 1.5));
    }

    if ($context->target->isGuarding ?? false) {
      $damage = max(1, intval($damage / 2));
    }

    $damage = ElementalDamage::scale($context->target, $this->element, $damage);

    $context->target->stats->currentHp -= $damage;
  }
}