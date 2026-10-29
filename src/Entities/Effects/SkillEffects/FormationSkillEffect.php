<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Battle\Formation\BattleFormation;
use Ichiloto\Engine\Battle\Formation\BattleFormationChange;
use Ichiloto\Engine\Entities\Skills\SkillGroupEffectContext;

/** Mutates the current battle's formation once per command after damage resolves. */
final class FormationSkillEffect extends GroupSkillEffect
{
  public function __construct(public readonly BattleFormationChange $operation = BattleFormationChange::SHUFFLE)
  {
    parent::__construct();
  }

  public function validateContext(?BattleFormation $formation, array $targets = []): void
  {
    if ($formation === null) { throw new \LogicException('Formation effects require the current battle formation.'); }
    $formation->validateTargets($targets);
  }

  public function applyToGroup(SkillGroupEffectContext $context): void
  {
    $this->validateContext($context->formation, $context->getTargets());
    $targets = $context->getEligibleTargets();
    $before = [];
    foreach ($context->getTargets() as $target) { $before[spl_object_id($target)] = $context->formation->getPosition($target); }
    match ($this->operation) {
      BattleFormationChange::SHUFFLE => $context->formation->shufflePositions($targets, $context->random),
      BattleFormationChange::MOVE_TO_FRONT => $context->formation->moveToFront($targets),
    };
    foreach ($context->getTargets() as $target) {
      $context->recordOutcome($target, $this->operation->value,
        $before[spl_object_id($target)] != $context->formation->getPosition($target));
    }
  }
}
