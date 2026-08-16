<?php

namespace Ichiloto\Engine\Battle\Actions;

use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattlerBattleView;
use Ichiloto\Engine\Battle\Resolution\CombatActionResult;
use Ichiloto\Engine\Battle\Resolution\CombatResolutionRequest;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\CombatTargetResult;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
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
    $executionId = $this->nextExecutionId();
    $targetResults = [];

    foreach ($targets as $target) {
      if (! $target instanceof Actor || $target->isKnockedOut) {
        continue;
      }

      $hit = $this->resolver->resolve(
        new CombatResolutionRequest(
          actionId: 'attack',
          executionId: $executionId,
          actor: $actor,
          target: $target,
          rawMagnitude: max(0, $actorView->stats->attack),
          kind: ResolutionKind::PHYSICAL_DAMAGE,
          element: method_exists($actor, 'getAttackElement') ? $actor->getAttackElement() : null,
          baseAccuracy: 95,
        ),
        $this->random,
      );
      $targetResults[] = new CombatTargetResult(CombatResolver::identity($target), [$hit]);
    }

    $this->lastResult = new CombatActionResult(
      'attack',
      $executionId,
      CombatResolver::identity($actor),
      $targetResults,
    );
  }
}
