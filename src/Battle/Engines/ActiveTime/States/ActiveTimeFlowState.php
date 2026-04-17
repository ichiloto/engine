<?php

namespace Ichiloto\Engine\Battle\Engines\ActiveTime\States;

use Assegai\Collections\Stack;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\BattleCommandOption;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\PlayerActionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use RuntimeException;

/**
 * Handles the wait-mode active-time battle flow.
 *
 * @package Ichiloto\Engine\Battle\Engines\ActiveTime\States
 */
class ActiveTimeFlowState extends PlayerActionState
{
  /**
   * @inheritDoc
   */
  public function enter(TurnStateExecutionContext $context): void
  {
    $context->ui->setState($context->ui->playerActionState);
    $this->menuStack = new Stack(MenuInterface::class);
    $this->activeCharacterIndex = -1;
    $this->selectionMode = self::MODE_COMMAND;
    $this->activeTargetIndex = -1;
    $context->setTurns([]);
    $context->ui->characterNameWindow->setActiveSelection(-1);
    $context->ui->commandWindow->blur();
    $context->ui->commandContextWindow->clear();
    $context->ui->fieldWindow->clearTargetIndicators();
    $context->ui->fieldWindow->clearMagicCastEffects();
    $context->ui->fieldWindow->clearStatChangePopups();
    $context->ui->hideMessage();
    $this->getAtbEngine()->refreshStatusWindow($context);

    $openingAlert = $this->getAtbEngine()->consumeEncounterAlert();

    if (is_string($openingAlert) && $openingAlert !== '') {
      $context->ui->showMessage($openingAlert);
      $context->ui->refresh();
      $this->pause(0.45);
      $context->ui->hideMessage();
    }

    $context->ui->refresh();
  }

  /**
   * @inheritDoc
   */
  public function update(TurnStateExecutionContext $context): void
  {
    if (empty($context->getLivingPartyBattlers()) || empty($context->getLivingTroopBattlers())) {
      $this->setState($this->engine->turnResolutionState);
      return;
    }

    if ($this->activeCharacter) {
      parent::update($context);
      $this->getAtbEngine()->refreshStatusWindow($context);
      return;
    }

    $this->getAtbEngine()->progressGauges($context);
    $this->getAtbEngine()->refreshStatusWindow($context);

    $readyBattler = $this->getAtbEngine()->claimNextReadyBattler();

    if (! $readyBattler instanceof CharacterInterface) {
      return;
    }

    if ($readyBattler instanceof Character) {
      $this->activateReadyCharacter($context, $readyBattler);
      return;
    }

    if ($readyBattler instanceof Enemy) {
      $this->executeEnemyTurn($context, $readyBattler);
    }
  }

  /**
   * Queues the confirmed action for the current ready battler.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function queueActionForActiveCharacter(TurnStateExecutionContext $context): void
  {
    if (! $this->activeCharacter) {
      return;
    }

    $selectedOption = $context->ui->commandContextWindow->getActiveItem();
    $targets = $this->resolveSelectedTargets($context, $selectedOption);

    if (! $selectedOption instanceof BattleCommandOption || empty($targets)) {
      return;
    }

    $actor = $this->activeCharacter;
    $this->selectionMode = self::MODE_COMMAND;
    $this->activeTargetIndex = -1;
    $this->activeCharacterIndex = -1;
    $context->ui->commandWindow->blur();
    $context->ui->commandContextWindow->clear();
    $context->ui->characterNameWindow->setActiveSelection(-1);
    $this->applyTargetingVisuals($context);
    $this->getAtbEngine()->queueImmediateTurn($context, $actor, $selectedOption->action, $targets);
  }

  /**
   * Cancelling a ready battler keeps them selected in wait mode.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function selectPreviousCharacter(TurnStateExecutionContext $context): void
  {
    if (! $this->activeCharacter) {
      return;
    }

    $context->ui->alert(sprintf('%s is ready to act.', $this->activeCharacter->name));
  }

  /**
   * ATB actions resolve immediately, so no item reservations are needed.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return array<string, int>
   */
  protected function getReservedItemCounts(TurnStateExecutionContext $context): array
  {
    return [];
  }

  /**
   * Activates command input for the ready party battler.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param Character $character The ready battler.
   * @return void
   */
  protected function activateReadyCharacter(TurnStateExecutionContext $context, Character $character): void
  {
    $partyBattlers = $context->party->battlers->toArray();
    $index = array_search($character, $partyBattlers, true);

    if (! is_int($index)) {
      return;
    }

    $this->activeCharacterIndex = $index;
    $this->loadCharacterActions($context);
  }

  /**
   * Queues a basic attack for the ready enemy battler.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param Enemy $enemy The ready enemy.
   * @return void
   */
  protected function executeEnemyTurn(TurnStateExecutionContext $context, Enemy $enemy): void
  {
    $targets = $context->getLivingPartyBattlers();

    if (empty($targets)) {
      $this->setState($this->engine->turnResolutionState);
      return;
    }

    $target = $targets[array_rand($targets)];
    $this->getAtbEngine()->queueImmediateTurn(
      $context,
      $enemy,
      new AttackAction('Attack'),
      [$target],
    );
  }

  /**
   * Returns the typed active-time engine.
   *
   * @return ActiveTimeBattleEngine
   */
  protected function getAtbEngine(): ActiveTimeBattleEngine
  {
    if (! $this->engine instanceof ActiveTimeBattleEngine) {
      throw new RuntimeException('Active-time flow requires the active-time engine.');
    }

    return $this->engine;
  }
}