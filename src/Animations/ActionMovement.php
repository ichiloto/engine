<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations;

use InvalidArgumentException;

/** Presentation travel authored on the reusable action-animation binding. */
enum ActionMovement: string
{
  case STEP = 'step';
  case RUN_TO_TARGET = 'run-to-target';

  /** A run binding requires one opposing recipient and a melee action, including its authored targeting shape. */
  public function validateAction(\Ichiloto\Engine\Battle\BattleAction $action,
    \Ichiloto\Engine\Entities\Interfaces\CharacterInterface $actor, array $targets): void
  {
    if ($this === self::STEP) { return; }
    $role = \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::getForAction($action);
    $basic = $action instanceof \Ichiloto\Engine\Battle\Actions\AttackAction
      || ($action instanceof \Ichiloto\Engine\Battle\Actions\SkillBattleAction
        && $action->skill instanceof \Ichiloto\Engine\Entities\Skills\BasicSkill);
    if (!array_is_list($targets) || count($targets) !== 1 || $targets[0] === $actor
      || $action->targetScope->number !== \Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber::ONE
      || $action->targetScope->side !== \Ichiloto\Engine\Entities\Enumerations\ItemScopeSide::ENEMY
      || ($action->targeting !== null && $action->targeting->shape !== \Ichiloto\Engine\Battle\Formation\BattleTargetShape::SCOPE)
      || ($action instanceof \Ichiloto\Engine\Battle\Actions\SkillBattleAction
        && $action->skill instanceof \Ichiloto\Engine\Entities\Skills\MagicSkill)
      || !in_array($role, [\Ichiloto\Engine\Battle\Presentation\BattlePoseRole::ATTACK, \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::SKILL], true)
      || ($basic && in_array(ActionAnimationResolver::getAttackRole($actor), ['attack-bow', 'attack-crossbow', 'attack-gun'], true))) {
      throw new InvalidArgumentException('Run-to-target requires a single opposing melee recipient; ranged, area, item and magic actions use step.');
    }
  }

  public static function readAnimation(array $data): self
  {
    if (!array_key_exists('movement', $data)) { return self::STEP; }
    $value = $data['movement'];
    return (is_string($value) ? self::tryFrom($value) : null)
      ?? throw new InvalidArgumentException('Animation movement must be step or run-to-target.');
  }
}
