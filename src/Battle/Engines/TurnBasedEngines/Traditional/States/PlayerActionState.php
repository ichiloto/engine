<?php

namespace Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States;

use Assegai\Collections\Stack;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\IO\Enumerations\AxisName;
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
    $this->menuStack->peek()?->update();
    $this->selectAction($context);
    $this->selectTarget($context);
    $this->confirmAction($context);
  }

  /**
   * Selects the action.
   *
   * @param TurnStateExecutionContext $context The context.
   */
  protected function selectAction(TurnStateExecutionContext $context): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      if ($v > 0) {
        $context->ui->commandWindow->selectNext();
      } else {
        $context->ui->commandWindow->selectPrevious();
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
  protected function confirmAction(TurnStateExecutionContext $context): void
  {
    if (Input::isButtonDown("action")) {
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

    $ui->commandWindow->commands = ['Attack', 'Magic', 'Summon', 'Item'];
    $ui->commandWindow->focus();
  }
}