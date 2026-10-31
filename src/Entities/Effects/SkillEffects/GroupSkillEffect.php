<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Battle\Formation\BattleFormation;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;
use Ichiloto\Engine\Entities\Skills\SkillGroupEffectContext;

/** An effect applied once after every recipient's individual effects have resolved. */
abstract class GroupSkillEffect extends SkillEffect
{
  public function __construct() { parent::__construct('0'); }

  final public function apply(SkillEffectContext $context): void
  {
    throw new \LogicException('Group effects require the complete command recipient context.');
  }

  public function validateContext(?BattleFormation $formation, array $targets = []): void {}

  abstract public function applyToGroup(SkillGroupEffectContext $context): void;
}
