<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Entities\Skills\SkillEffectContext;

/**
 * Cures states on the target.
 *
 * ```php
 * new RemoveStateSkillEffect(['poison'])   // the Antidote effect
 * ```
 *
 * @package Ichiloto\Engine\Entities\Effects\SkillEffects
 */
class RemoveStateSkillEffect extends SkillEffect
{
  /**
   * @param string[] $stateIds The states to cure.
   */
  public function __construct(
    protected(set) array $stateIds,
  )
  {
    parent::__construct('0');
  }

  /**
   * @inheritDoc
   */
  public function apply(SkillEffectContext $context): void
  {
    foreach (is_array($context->target) ? $context->target : [$context->target] as $target) {
      if (! method_exists($target, 'removeState')) {
        continue;
      }

      foreach ($this->stateIds as $stateId) {
        $target->removeState(strval($stateId));
      }
    }
  }
}
