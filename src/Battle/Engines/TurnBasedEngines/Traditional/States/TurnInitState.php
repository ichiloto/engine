<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Exceptions\NotImplementedException;
use Ichiloto\Engine\Util\Debug;

/**
 * Represents the turn init state.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States
 */
class TurnInitState extends TurnState
{

  /**
   * @inheritDoc
   */
  public function update(TurnStateExecutionContext $context): void
  {
    $this->resetBuffsAndDebuffs($context);
    $this->determineTurnOrder($context);
    $this->updateUI($context);
    $this->setState($this->engine->playerActionState);
  }

  /**
   * Resets the buffs and debuffs.
   *
   * @param TurnStateExecutionContext $context The context.
   */
  protected function resetBuffsAndDebuffs(TurnStateExecutionContext $context): void
  {
    // TODO: Implement resetBuffsAndDebuffs() method.
  }

  /**
   * Determines the turn order.
   *
   * @param TurnStateExecutionContext $context The context.
   */
  protected function determineTurnOrder(TurnStateExecutionContext $context): void
  {
    $this->engine->turnQueue->clear();

    /** @var CharacterInterface[] $battlers */
    $battlers = [...$context->party->battlers->toArray(), ...$context->troop->members->toArray()];
    usort($battlers, fn(CharacterInterface $a, CharacterInterface $b) => $a->stats->speed <=> $b->stats->speed);
    foreach ($battlers as $battler) {
      $turn = new Turn($battler);
      $this->engine->turnQueue->enqueue($turn);
    }
  }

  /**
   * Updates the UI.
   *
   * @param TurnStateExecutionContext $context The context.
   */
  private function updateUI(TurnStateExecutionContext $context): void
  {
    // TODO: Implement updateUI() method.
  }
}