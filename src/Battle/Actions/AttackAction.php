<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface as Actor;

class AttackAction extends BattleAction
{
  /**
   * @inheritDoc
   */
  public function execute(Actor $actor, array $targets): void
  {
    if ($actor->isKnockedOut) {
      return;
    }

    $attack = $actor instanceof Character ? $actor->effectiveStats->attack : $actor->stats->attack;

    foreach ($targets as $target) {
      if (! $target instanceof Actor || $target->isKnockedOut) {
        continue;
      }

      $defence = $target instanceof Character ? $target->effectiveStats->defence : $target->stats->defence;
      $damage = max(1, $attack - intval($defence / 2));
      $target->stats->currentHp -= $damage;
    }
  }
}
