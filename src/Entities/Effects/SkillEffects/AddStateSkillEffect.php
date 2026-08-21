<?php

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Entities\Skills\SkillEffectContext;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\Util\Debug;

/**
 * Attempts to inflict a state on the target.
 *
 * ```php
 * new AddStateSkillEffect('poison', 80)   // 80% chance to poison
 * ```
 *
 * The target's state resistances scale the chance (0 grants immunity).
 *
 * @package Ichiloto\Engine\Entities\Effects\SkillEffects
 */
class AddStateSkillEffect extends SkillEffect
{
  /**
   * @param string $stateId The state to inflict.
   * @param int $chancePercent The base infliction chance.
   */
  public function __construct(
    protected(set) string $stateId,
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
    $state = StateRegistry::get($this->stateId);

    if ($state === null) {
      Debug::warn(sprintf('Cannot inflict unknown state: %s', $this->stateId));
      return;
    }

    foreach (is_array($context->target) ? $context->target : [$context->target] as $target) {
      if (method_exists($target, 'addState')) {
        $context->recordSecondaryOutcome(
          'state',
          $state->id,
          $target->addState($state, $this->chancePercent, $context->random),
        );
      }
    }
  }
}
