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
    if (Input::isButtonDown("back")) {
      $this->state->setMode(new SelectIemMenuCommandMode($this->state));
    }

    $this->state->getGameScene()->party->inventory->sort();
    $this->state->selectionPanel->updateContent();
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    // Nothing to do here.
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    // Nothing to do here.
  }
}