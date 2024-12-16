<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Util\Debug;

/**
 * Class ItemMenuItemSelectionMode. Represents an item menu item selection mode.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu\Modes
 */
class UseItemMode extends ItemMenuMode
{
  /**
   * @inheritDoc
   */
  public function update(): void
  {
    if (Input::isButtonDown("back")) {
      $this->state->setMode(new SelectIemMenuCommandMode($this->state));
    }

    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      if ($v > 0) {
        $this->state->selectionPanel->selectNext();
      } else {
        $this->state->selectionPanel->selectPrevious();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->state->selectionPanel->focus();
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->state->selectionPanel->blur();
  }
}