<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Entities\Skills\SkillEffectContext;
use RuntimeException;

/**
 * Represents a skill effect.
 *
 * @package Ichiloto\Engine\Entities\Effects
 */
abstract class SkillEffect
{
  /**
   * Creates a new instance of the skill effect.
   *
   * @param string $formula The formula of the effect.
   * @param string|null $element The element of the effect.
   * @param float $variance The variance of the effect.
   * @param bool $isCriticalHit Indicates whether the effect is a critical hit.
   */
  public function __construct(
    protected(set) string $formula,
    protected(set) ?string $element = null,
    protected(set) float $variance = .2 {
      set {
        $this->variance = clamp($value, 0, 1);
      }
    },
    protected(set) bool $isCriticalHit = false,
  )
  {
  }

  /**
   * Applies the effect to the given context.
   *
   * @param SkillEffectContext $context The context to apply the effect to.
   */
  abstract public function apply(SkillEffectContext $context): void;

  public function getValue(SkillEffectContext $context): int {
    $user = $context->user;
    $target = $context->target;

    $value = eval("return $this->formula;") ?? throw new RuntimeException("Invalid formula: $this->formula");
    $minMultiplier = 1 - $this->variance;
    $maxMultiplier = 1 + $this->variance;
    $minValue = intval($value * $minMultiplier);
    $maxValue = intval($value * $maxMultiplier);

    return rand($minValue, $maxValue);
  }
}