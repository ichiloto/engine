<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Battle\BattleConclusion;
use Ichiloto\Engine\Scenes\Battle\BattleScene;

/**
 * Represents the turn resolution state.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States
 */
class TurnResolutionState extends TurnState
{
  /**
   * @inheritDoc
   */
  public function update(TurnStateExecutionContext $context): void
  {
    $scene = $context->game->sceneManager->currentScene;

    if (! $scene instanceof BattleScene) {
      return;
    }

    if ($context->getLivingTroopBattlers() !== [] && $context->partyRoster->promoteReservesAfterWipeout()) {
      $this->engine->handlePartyRosterChange($context);
    }

    $conclusion = new BattleConclusion($context->game, $context->party, $context->troop);

    if (empty($context->getLivingPartyBattlers())) {
      $conclusion->concludeDefeat($scene);
      return;
    }

    if (empty($context->getLivingTroopBattlers())) {
      $conclusion->concludeVictory($scene);
      return;
    }

    $this->setState($this->engine->turnInitState);
  }

}
