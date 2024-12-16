<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\ItemMenuMode;
use Ichiloto\Engine\IO\Input;

class DiscardItemMode extends ItemMenuMode
{

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    if (Input::isButtonDown("back")) {
      $this->state->setMode(new SelectIemMenuCommandMode($this->state));
    }

    // TODO: Implement update() method.
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    // TODO: Implement enter() method.
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    // TODO: Implement exit() method.
  }
}