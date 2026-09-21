<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\IO\Input;

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
      play_sound(SystemSound::CANCEL);
      $this->state->setMode(new SelectIemMenuCommandMode($this->state));
      return;
    }

    if (Input::isButtonDown("confirm")) {
      if ($this->state->selectionPanel->activeItem === null) { return; }
      play_sound(SystemSound::CONFIRM);
      $this->state->setMode(new SelectItemTargetMode($this->state));
      if ($mode = $this->state->mode) {
        if ($mode instanceof SelectItemTargetMode) {
          $mode->previousMode = $this;
        }
      }
      return;
    }

    $this->navigateItems();
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->state->selectionPanel->focus();

    if ($this->inventory->isEmpty) {
      $this->state->setMode(new SelectIemMenuCommandMode($this->state));
    }
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    // Do nothing.
  }
}
