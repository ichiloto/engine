<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

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

    // Elemental affinity scales the final damage: 2.0 weak, 0.5 resist,
    // 0.0 null, negative absorbs (the hit heals instead).
    if (method_exists($context->target, 'getElementMultiplier')) {
      $multiplier = $context->target->getElementMultiplier($this->element);

      if ($multiplier !== 1.0) {
        $context->target->lastElementReaction = match (true) {
          $multiplier < 0.0 => 'ABSORB',
          $multiplier === 0.0 => 'NULL',
          $multiplier < 1.0 => 'RESIST',
          default => 'WEAK!',
        };
      }

      $damage = intval(round($damage * $multiplier));

      if ($multiplier > 0.0) {
        $damage = max(1, $damage);
      }
    }

    $context->target->stats->currentHp -= $damage;
  }
}