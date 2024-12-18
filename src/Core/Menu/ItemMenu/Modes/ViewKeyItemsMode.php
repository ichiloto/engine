<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\ItemMenuMode;
use Ichiloto\Engine\IO\Input;

class ViewKeyItemsMode extends ItemMenuMode
{

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    // TODO: Implement update() method.
    if (Input::isButtonDown("back")) {
      $this->state->setMode(new SelectIemMenuCommandMode($this->state));
    }

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