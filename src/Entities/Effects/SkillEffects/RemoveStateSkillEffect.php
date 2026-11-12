<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Entities\Skills\SkillEffectContext;

/**
 * Cures states on the target, each one it has with the authored chance.
 *
 * ```php
 * new RemoveStateSkillEffect(['poison'])       // the Antidote effect
 * new RemoveStateSkillEffect(['poison'], 75)   // a treatment that works three times in four
 * ```
 *
 * @package Ichiloto\Engine\Entities\Effects\SkillEffects
 */
class RemoveStateSkillEffect extends SkillEffect
{
  /**
   * @param string[] $stateIds The states to cure.
   * @param int $chancePercent The chance of curing each listed state the target has.
   */
  public function __construct(
    protected(set) array $stateIds,
    protected(set) int $chancePercent = 100,
  )
  {
    parent::__construct('0');
  }

  /**
   * @inheritDoc
   */
  public function apply(SkillEffectContext $context): void
  {
    $chance = intval(clamp($this->chancePercent, 0, 100));

    foreach (is_array($context->target) ? $context->target : [$context->target] as $target) {
      if (! method_exists($target, 'removeState')) {
        continue;
      }

      foreach ($this->stateIds as $stateId) {
        $stateId = strval($stateId);

        // Only a state the target has is rolled for: there is nothing to treat otherwise.
        if (method_exists($target, 'hasState') && ! $target->hasState($stateId)) {
          continue;
        }

        $succeeds = $chance >= 100 || ($chance > 0 && $context->random->nextInt(1, 100) <= $chance);
        $context->recordSecondaryOutcome('state-removal', $stateId, $succeeds && $target->removeState($stateId));
      }
    }
  }
}
