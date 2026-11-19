<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\CrisisSkillBattleAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Entities\Character;
use InvalidArgumentException;

/** When authored, AP is earned by chosen battle commands and kept between battles. */
final readonly class ActionPointPolicy
{
  public function __construct(public int $basicAttackGain)
  {
    if ($basicAttackGain < 0) {
      throw new InvalidArgumentException('battle.actionPoints.basicAttackGain must be a nonnegative integer.');
    }
  }

  public static function fromBattleData(mixed $battle): ?self
  {
    if (!is_array($battle) || !array_key_exists('actionPoints', $battle)) {
      return null;
    }

    $data = $battle['actionPoints'];
    if (!is_array($data) || array_keys($data) !== ['basicAttackGain'] || !is_int($data['basicAttackGain'])) {
      throw new InvalidArgumentException('battle.actionPoints must declare one nonnegative integer basicAttackGain.');
    }

    return new self($data['basicAttackGain']);
  }

  /** Credit an executed player choice once, regardless of hit count or outcome. */
  public function creditAction(Character $actor, BattleAction $action): void
  {
    if ($action->lastResult === null || $action->lastResult->targetCount() === 0) {
      return;
    }

    $gain = match (true) {
      $action instanceof AttackAction => $this->basicAttackGain,
      $action instanceof SkillBattleAction
        && !$action instanceof CrisisSkillBattleAction
        && $action->skill->apCost === 0
        && in_array(BattleCommandCatalog::getActionType($action), [
          BattleCommandType::ATTACK, BattleCommandType::SKILL, BattleCommandType::MAGIC,
        ], true) => max(0, $action->skill->invocation->apGain),
      default => 0,
    };
    $actor->stats->currentAp += min($gain, $actor->stats->totalAp - $actor->stats->currentAp);
  }
}
