<?php

namespace Ichiloto\Engine\Battle;

/**
 * Applies a target's elemental affinity to damage.
 *
 * Skill effects and basic attacks both scale damage by the same rule, and a
 * rule written twice drifts. This is the one place that knows how a
 * multiplier becomes damage and a reaction label: 2.0 weak, 0.5 resist,
 * 0.0 null, negative absorbs (the hit heals instead).
 *
 * @package Ichiloto\Engine\Battle
 */
final class ElementalDamage
{
  /**
   * Scales damage by the target's affinity for an element.
   *
   * @param object $target The battler taking the hit.
   * @param string|null $element The attacking element; null is neutral.
   * @param int $damage The damage before affinity.
   * @return int The damage after affinity; negative heals.
   */
  public static function scale(object $target, ?string $element, int $damage): int
  {
    if (! method_exists($target, 'getElementMultiplier')) {
      return $damage;
    }

    $multiplier = $target->getElementMultiplier($element);

    if ($multiplier !== 1.0 && property_exists($target, 'lastElementReaction')) {
      $target->lastElementReaction = match (true) {
        $multiplier < 0.0 => 'ABSORB',
        $multiplier === 0.0 => 'NULL',
        $multiplier < 1.0 => 'RESIST',
        default => 'WEAK!',
      };
    }

    $damage = intval(round($damage * $multiplier));

    if ($multiplier > 0.0) {
      // Floored at 1 so a resisted hit still lands; null and absorb are the
      // only ways damage reaches zero or below.
      $damage = max(1, $damage);
    }

    return $damage;
  }
}
