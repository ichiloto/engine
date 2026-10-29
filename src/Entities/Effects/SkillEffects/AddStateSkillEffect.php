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
 * The target's state resistances scale the chance (0 grants immunity). A
 * target knocked out by the skill's earlier effects is not afflicted, so a
 * spell that damages and then adds a state leaves only its survivors in it.
 *
 * @package Ichiloto\Engine\Entities\Effects\SkillEffects
 */
class AddStateSkillEffect extends SkillEffect
{
  protected(set) bool $guaranteed = false;
  /**
   * @param string $stateId The state to inflict.
   * @param int $chancePercent The base infliction chance.
   */
  public function __construct(
    protected(set) string $stateId,
    protected(set) int $chancePercent = 100,
    bool $guaranteed = false,
  )
  {
    if ($guaranteed && $chancePercent !== 100) {
      throw new \InvalidArgumentException('A guaranteed state effect must use chancePercent 100.');
    }
    $this->guaranteed = $guaranteed;
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
        // Only a survivor is left with the state: an earlier effect of the same skill may have knocked the target out.
        $context->recordSecondaryOutcome(
          'state',
          $state->id,
          ! $target->isKnockedOut
            && $target->addState($state, $this->chancePercent, $context->random, $target === $context->user, $this->guaranteed),
        );
      }
    }
  }
}
