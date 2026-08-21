<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Battle\EnemyActionEvaluator;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

class EnemyActionState extends TurnState
{
  /**
   * @inheritDoc
   */
  public function update(TurnStateExecutionContext $context): void
  {
    $partyTargets = $context->getLivingPartyBattlers();

    if (empty($partyTargets)) {
      $this->setState($this->engine->turnResolutionState);
      return;
    }

    $maxPartyLevel = 1;

    foreach ($partyTargets as $battler) {
      if ($battler instanceof Character) {
        $maxPartyLevel = max($maxPartyLevel, $battler->level);
      }
    }

    foreach ($context->getLivingTroopBattlers() as $enemy) {
      $turn = $context->findTurnForBattler($enemy);

      if ($turn === null) {
        continue;
      }

      [$turn->action, $turn->targets] = $this->chooseEnemyAction($context, $enemy, $partyTargets, $maxPartyLevel);
    }

    $context->ui->commandContextWindow->clear();

    $this->setState($this->engine->actionExecutionState);
  }

  /**
   * Chooses an enemy's action and targets from its authored patterns,
   * falling back to a basic attack on a random party member.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param CharacterInterface $enemy The acting enemy.
   * @param CharacterInterface[] $partyTargets The living party battlers.
   * @param int $maxPartyLevel The highest level in the player party.
   * @return array{0: \Ichiloto\Engine\Battle\BattleAction, 1: CharacterInterface[]} The action and its targets.
   */
  protected function chooseEnemyAction(
    TurnStateExecutionContext $context,
    CharacterInterface $enemy,
    array $partyTargets,
    int $maxPartyLevel
  ): array
  {
    $gameState = $context->getGameState();

    return EnemyActionEvaluator::chooseAction(
      $enemy,
      $partyTargets,
      $context->getLivingTroopBattlers(),
      $context->roundNumber,
      $maxPartyLevel,
      $gameState === null ? null : $gameState->getSwitch(...),
    );
  }
}
