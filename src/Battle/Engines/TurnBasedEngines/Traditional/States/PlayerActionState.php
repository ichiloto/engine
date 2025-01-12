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
    $this->activeCharacterIndex = 0;
    $this->loadCharacterActions();
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

  /**
   * Selects the target.
   *
   * @param TurnStateExecutionContext $context The context.
   */
  protected function selectTarget(TurnStateExecutionContext $context): void
  {
  }

  /**
   * Confirms the action.
   *
   * @param TurnStateExecutionContext $context The context.
   */
  protected function handleActions(TurnStateExecutionContext $context): void
  {
    if (Input::isButtonDown("action")) {
      $context->ui->state->confirm();
      $this->selectNextCharacter($context);
    }

    if (Input::isAnyKeyPressed([KeyCode::C, KeyCode::c])) {
      $this->selectNextCharacter($context);
      $context->ui->state->cancel();
    }
  }

  /**
   * Loads the character actions.
   *
   * @return void
   */
  protected function loadCharacterActions(): void
  {
    /** @var TraditionalTurnBasedBattleEngine $engine */
    $engine = $this->engine;
    $ui = $engine->battleConfig->ui;

    $ui->characterNameWindow->activeIndex = $this->activeCharacterIndex;

    $ui->commandWindow->commands = array_map(fn(BattleAction $action) => $action->name, $this->activeCharacter->commandAbilities);
    $ui->commandWindow->focus();
  }

  protected function selectNextCharacter(TurnStateExecutionContext $context): void
  {
    $this->activeCharacterIndex = wrap($this->activeCharacterIndex + 1, 0, count($context->party->battlers) - 1);
    $this->loadCharacterActions();
  }
}