<?php

namespace Ichiloto\Engine\Battle\UI\States;

use Override;

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