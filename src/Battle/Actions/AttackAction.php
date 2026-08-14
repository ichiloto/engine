<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\ElementalDamage;
use Ichiloto\Engine\Battle\BattlerBattleView;
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

    $actorView = new BattlerBattleView($actor);
    $attack = $actorView->stats->attack;

    foreach ($targets as $target) {
      if (! $target instanceof Actor || $target->isKnockedOut) {
        continue;
      }

      $targetView = new BattlerBattleView($target);

      // A basic attack lands 95% of the time before grace and evasion.
      $hitChance = intval(clamp(95 + $actorView->stats->grace - $targetView->stats->evasion, 5, 100));

      if (rand(1, 100) > $hitChance) {
        continue; // The battle UI reads the unchanged stats as a MISS.
      }

      $damage = max(1, $attack - intval($targetView->stats->defence / 2));

      $critChance = intval(clamp(5 + intdiv($actorView->stats->grace, 10), 1, 50));

      if (rand(1, 100) <= $critChance) {
        $damage = intval(round($damage * 1.5));

        if (method_exists($target, 'addState')) {
          $target->lastHitWasCritical = true;
        }
      }

      if ($target->isGuarding ?? false) {
        $damage = max(1, intval($damage / 2));
      }

      // The weapon's element meets the target's affinities, exactly as a
      // skill's element does. A plain weapon attacks neutrally, and until
      // now a basic attack ignored affinities altogether -- an enemy that
      // absorbs Water still took plain hits at full damage.
      $damage = ElementalDamage::scale(
        $target,
        method_exists($actor, 'getAttackElement') ? $actor->getAttackElement() : null,
        $damage,
      );

      $target->stats->currentHp -= $damage;
    }
  }
}
