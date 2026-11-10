<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Entities\Effects\SkillEffects;

use Ichiloto\Engine\Battle\BattleReactions;
use Ichiloto\Engine\Battle\Formation\BattleTargetShape;
use Ichiloto\Engine\Battle\Formation\BattleTargeting;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillGroupEffectContext;
use InvalidArgumentException;

final class PrepareResponseWindowSkillEffect extends GroupSkillEffect
{
  public function __construct(public readonly string $skill, public readonly int $responseTurns)
  {
    parent::__construct();
    if (trim($skill) === '' || $responseTurns < 1) {
      throw new InvalidArgumentException('A response window requires a follow-up skill and at least one response turn.');
    }
  }

  public static function canTargetOpposingParty(Occasion $occasion, ItemScope $scope, ?BattleTargeting $targeting): bool
  {
    return $occasion === Occasion::BATTLE_SCREEN
      && $scope->side === ItemScopeSide::ENEMY
      && $scope->number === ItemScopeNumber::ALL
      && $scope->status === ItemScopeStatus::ALIVE
      && ($targeting?->shape ?? BattleTargetShape::SCOPE) === BattleTargetShape::SCOPE;
  }

  public static function hasWarning(Skill $skill): bool
  {
    return array_any($skill->effects, static fn(SkillEffect $effect): bool => $effect instanceof self);
  }

  public function validate(Skill $warning, ?BattleReactions $reactions): void
  {
    if ($reactions === null || count($warning->effects) !== 1
      || !self::canTargetOpposingParty($warning->occasion, $warning->scope, $warning->battleTargeting)) {
      throw new InvalidArgumentException('A response-window warning requires battle context and must target all living opponents on its own.');
    }
    $reactions->resolveResponseSkill($this->skill);
  }

  public function applyToGroup(SkillGroupEffectContext $context): void
  {
    $reactions = $context->reactions ?? throw new InvalidArgumentException('A response window requires battle context.');
    $reactions->responseWindows->prepare($context->actor, $reactions->resolveResponseSkill($this->skill),
      $context->getTargets(), $this->responseTurns);
  }
}
