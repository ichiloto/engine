<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Assegai\Collections\Stack;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;

/**
 * Represents the player action state.
 *
 * @package Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States
 */
class PlayerActionState extends TurnState
{
  /**
   * @var int The active character index.
   */
  protected int $activeCharacterIndex = -1;
  protected ?Character $activeCharacter {
    get {
      return $this->engine->battleConfig->party->battlers->toArray()[$this->activeCharacterIndex] ?? null;
    }
  }
  /**
   * @var Stack<MenuInterface>|null The menu stack.
   */
  protected ?Stack $menuStack = null;

  /**
   * @inheritDoc
   */
  public function enter(TurnStateExecutionContext $context): void
  {
    $context->ui->setState($context->ui->playerActionState);
    $this->menuStack = new Stack(MenuInterface::class);
    $this->activeCharacterIndex = -1;

    if (empty($context->getLivingPartyBattlers())) {
      $this->setState($this->engine->enemyActionState);
      return;
    }

    $this->selectNextCharacter($context, true);
  }

  /**
   * @inheritDoc
   */
  public function update(TurnStateExecutionContext $context): void
  {
    $this->handleNavigation($context);
    $this->selectTarget($context);
    $this->handleActions($context);
  }

  /**
   * Selects the action.
   *
   * @param TurnStateExecutionContext $context The context.
   */
  protected function handleNavigation(TurnStateExecutionContext $context): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      if ($v > 0) {
        $context->ui->state->selectNext();
      } else {
        $context->ui->state->selectPrevious();
      }
    }
  }

  protected function selectTarget(TurnStateExecutionContext $context): void
  {
    // Reserved for command-specific sub-selection UIs such as spells, summons, and items.
  }

  /**
   * Confirms or rewinds the current selection.
   *
   * @param TurnStateExecutionContext $context The context.
   */
  protected function handleActions(TurnStateExecutionContext $context): void
  {
    if (Input::isButtonDown('action')) {
      $this->queueActionForActiveCharacter($context);
    }

    if (Input::isAnyKeyPressed([KeyCode::C, KeyCode::c])) {
      $this->selectPreviousCharacter($context);
    }
  }

  /**
   * Loads the character actions.
   *
   * @return void
   */
  protected function loadCharacterActions(): void
  {
    if (! $this->activeCharacter) {
      return;
    }

    /** @var TraditionalTurnBasedBattleEngine $engine */
    $engine = $this->engine;
    $ui = $engine->battleConfig->ui;

    $ui->characterNameWindow->activeIndex = $this->activeCharacterIndex;
    $ui->commandWindow->commands = array_map(
      fn(BattleAction $action) => $action->name,
      $this->activeCharacter->commandAbilities
    );
    $ui->commandWindow->focus();
  }

  /**
   * Moves to the next character who still needs an action.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @param bool $resetToStart Whether to start from the beginning of the party.
   * @return void
   */
  protected function selectNextCharacter(TurnStateExecutionContext $context, bool $resetToStart = false): void
  {
    $partyBattlers = $context->party->battlers->toArray();
    $startIndex = $resetToStart ? 0 : $this->activeCharacterIndex + 1;

    foreach ($partyBattlers as $index => $battler) {
      if ($index < $startIndex) {
        continue;
      }

      $turn = $context->findTurnForBattler($battler);

      if ($battler->isKnockedOut || $turn?->action !== null) {
        continue;
      }

      $this->activeCharacterIndex = $index;
      $this->loadCharacterActions();
      $this->selectTarget($context);
      return;
    }

    $this->activeCharacterIndex = -1;
    $context->ui->characterNameWindow->activeIndex = -1;
    $context->ui->commandWindow->blur();
    $context->ui->commandContextWindow->clear();
    $this->setState($this->engine->enemyActionState);
  }

  /**
   * Queues the selected action for the current character.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function queueActionForActiveCharacter(TurnStateExecutionContext $context): void
  {
    if (! $this->activeCharacter) {
      return;
    }

    $activeCommandIndex = $context->ui->commandWindow->activeCommandIndex;
    $selectedAction = $this->activeCharacter->commandAbilities[$activeCommandIndex] ?? null;
    $turn = $context->findTurnForBattler($this->activeCharacter);
    $target = $this->getDefaultTarget($context);

    if ($selectedAction === null || $turn === null || $target === null) {
      return;
    }

    $turn->action = $selectedAction;
    $turn->targets = [$target];

    $context->ui->alert(sprintf('%s queued %s.', $this->activeCharacter->name, $selectedAction->name));
    $this->selectNextCharacter($context);
  }

  /**
   * Rewinds to the previous queued character so their action can be changed.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return void
   */
  protected function selectPreviousCharacter(TurnStateExecutionContext $context): void
  {
    $partyBattlers = $context->party->battlers->toArray();
    $startIndex = $this->activeCharacterIndex < 0 ? count($partyBattlers) - 1 : $this->activeCharacterIndex - 1;

    for ($index = $startIndex; $index >= 0; $index--) {
      $battler = $partyBattlers[$index];
      $turn = $context->findTurnForBattler($battler);

      if ($battler->isKnockedOut || $turn === null || $turn->action === null) {
        continue;
      }

      $turn->action = null;
      $turn->targets = [];
      $this->activeCharacterIndex = $index;
      $this->loadCharacterActions();
      $this->selectTarget($context);
      $context->ui->alert(sprintf('%s action cleared.', $battler->name));
      return;
    }

    if ($this->activeCharacterIndex < 0) {
      $this->selectNextCharacter($context, true);
    }
  }

  /**
   * Returns the default target for the active character.
   *
   * @param TurnStateExecutionContext $context The turn context.
   * @return CharacterInterface|null
   */
  protected function getDefaultTarget(TurnStateExecutionContext $context): ?CharacterInterface
  {
    return $context->getLivingTroopBattlers()[0] ?? null;
  }
}
