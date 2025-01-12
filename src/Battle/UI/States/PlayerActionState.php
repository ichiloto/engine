<?php

namespace Ichiloto\Engine\Battle\UI\States;

use Override;

/**
 * Represents the player action state.
 *
 * @package Ichiloto\Engine\Battle\UI\States
 */
class PlayerActionState extends BattleScreenState
{
  /**
   * @inheritDoc
   */
  #[Override]
  public function selectPrevious(int $step = 1): void
  {
    $this->battleScreen->commandWindow->selectPrevious();
  }

  /**
   * @inheritDoc
   */
  public function confirm(): void
  {
    // Select the current command.
    if (($activeIndex = $this->battleScreen->commandWindow->activeCommandIndex)> -1) {
      $activeCommandName = $this->battleScreen->commandWindow->commands[$activeIndex];
      $this->battleScreen->alert($activeCommandName);
    }
  }

  /**
   * @inheritDoc
   */
  public function cancel(): void
  {
    $this->battleScreen->commandWindow->focus();
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function selectNext(int $step = 1): void
  {
    $this->battleScreen->commandWindow->selectNext();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    // TODO: Implement update() method.
  }
}