<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Ichiloto\Engine\IO\Input;

/**
 * Class ItemMenuSortItemMode. Represents an item menu sort item mode.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu\Modes
 */
class SortItemsMode extends ItemMenuMode
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