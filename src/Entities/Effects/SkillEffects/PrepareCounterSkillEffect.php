<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Battle\CounterAttackRule;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;
use Ichiloto\Engine\Entities\States\StateRegistry;
use InvalidArgumentException;

/** Draws a chosen opponent's next attack into a one-shot evasive stance. */
final class PrepareCounterSkillEffect extends SkillEffect
{
  public function __construct(public readonly string $skill, public readonly string $stateId)
  {
    new CounterAttackRule($skill);
    if (trim($stateId) === '' || trim($stateId) !== $stateId) {
      throw new InvalidArgumentException('prepare_counter.stateId must name a stance state.');
    }
    parent::__construct('0');
  }

  public function apply(SkillEffectContext $context): void
  {
    $this->validate($context->reactions);
    $reactions = $context->reactions ?? throw new InvalidArgumentException('Prepared counters require a battle reaction context.');
    $state = StateRegistry::get($this->stateId)
      ?? throw new InvalidArgumentException('Unknown prepared counter stance: ' . $this->stateId);
    $targets = is_array($context->target) ? $context->target : [$context->target];
    foreach ($targets as $target) {
      $context->recordSecondaryOutcome('preparedCounter', $this->stateId,
        $reactions->prepareCounter($context->user, $target, new CounterAttackRule($this->skill), $state));
    }
  }

  public function validate(?\Ichiloto\Engine\Battle\BattleReactions $reactions): void
  {
    if ($reactions === null) { throw new InvalidArgumentException('Prepared counters require a battle reaction context.'); }
    $state = StateRegistry::get($this->stateId)
      ?? throw new InvalidArgumentException('Unknown prepared counter stance: ' . $this->stateId);
    $reactions->validatePreparation(new CounterAttackRule($this->skill), $state);
  }
}
