<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;

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
    if (empty($context->getLivingPartyBattlers()) || empty($context->getLivingTroopBattlers())) {
      $this->setState($this->engine->turnResolutionState);
      return;
    }

    $context->roundNumber++;

    // A preemptive strike or ambush drops the surprised side's turns for
    // the opening round only.
    $firstStrike = $context->roundNumber === 1
      ? strval($this->engine->battleConfig->settings['firstStrike'] ?? '')
      : '';

    $this->determineTurnOrder($context, match ($firstStrike) {
      'party' => 'troop',
      'troop' => 'party',
      default => null,
    });

    if ($firstStrike === 'party') {
      $context->ui->alert('Preemptive strike! The party moves first.');
    } elseif ($firstStrike === 'troop') {
      $context->ui->alert('Ambushed! The enemy strikes first.');
    }

    $this->updateUI($context);
    $this->setState($firstStrike === 'troop' ? $this->engine->enemyActionState : $this->engine->playerActionState);
  }

  /**
   * Determines the turn order.
   *
   * @param TurnStateExecutionContext $context The context.
   */
  protected function determineTurnOrder(TurnStateExecutionContext $context, ?string $excludedSide = null): void
  {
    $this->engine->turnQueue->clear();
    $turns = [];

    /** @var CharacterInterface[] $battlers */
    $battlers = array_values(array_filter(
      [...$context->party->battlers->toArray(), ...$context->troop->members->toArray()],
      fn(CharacterInterface $battler) => ! $battler->isKnockedOut
        && ! ($excludedSide === 'troop' && $battler instanceof \Ichiloto\Engine\Entities\Enemies\Enemy)
        && ! ($excludedSide === 'party' && ! $battler instanceof \Ichiloto\Engine\Entities\Enemies\Enemy)
    ));

    usort($battlers, fn(CharacterInterface $a, CharacterInterface $b) => $this->getBattlerSpeed($b) <=> $this->getBattlerSpeed($a));

    foreach ($battlers as $battler) {
      $turn = new Turn($battler);
      $turns[] = $turn;
      $this->engine->turnQueue->enqueue($turn);
    }

    $context->setTurns($turns);
  }

  /**
   * Updates the UI.
   *
   * @param TurnStateExecutionContext $context The context.
   */
  private function updateUI(TurnStateExecutionContext $context): void
  {
    $context->ui->characterStatusWindow->setCharacters($context->party->battlers->toArray());
    $context->ui->characterNameWindow->setActiveSelection(-1);
    $context->ui->commandContextWindow->clear();
    $context->ui->fieldWindow->clearTargetIndicators();
    $context->ui->refresh();
  }

  /**
   * Returns the battler's effective speed.
   *
   * @param CharacterInterface $battler The battler to inspect.
   * @return int
   */
  protected function getBattlerSpeed(CharacterInterface $battler): int
  {
    return new \Ichiloto\Engine\Battle\BattlerBattleView($battler)->stats->speed;
  }
}
