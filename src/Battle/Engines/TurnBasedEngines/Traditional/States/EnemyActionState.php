<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Battle\Actions\AttackAction;

class EnemyActionState extends TurnState
{
  /**
   * @inheritDoc
   */
  public function update(TurnStateExecutionContext $context): void
  {
    $targets = $context->getLivingPartyBattlers();

    if (empty($targets)) {
      $this->setState($this->engine->turnResolutionState);
      return;
    }

    foreach ($context->getLivingTroopBattlers() as $enemy) {
      $turn = $context->findTurnForBattler($enemy);

      if ($turn === null) {
        continue;
      }

      $turn->action = new AttackAction('Attack');
      $turn->targets = [$targets[array_rand($targets)]];
    }

    $context->ui->commandContextWindow->clear();

    $this->setState($this->engine->actionExecutionState);
  }
}
