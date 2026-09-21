<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Exception;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\IO\Input;

class DiscardItemMode extends ItemMenuMode
{

  /**
   * @inheritDoc
   * @throws Exception If the item cannot be discarded.
   */
  public function update(): void
  {
    if (Input::isButtonDown("back")) {
      play_sound(SystemSound::CANCEL);
      $this->state->setMode(new SelectIemMenuCommandMode($this->state));
      return;
    }

    $this->navigateItems();

    if (Input::isButtonDown("confirm")) {
      if ($this->state->selectionPanel->activeItem === null) { return; }
      if (confirm("Are you sure you want to discard this item?")) {
        $this->state->getGameScene()->party->inventory->removeItems($this->state->selectionPanel->activeItem);
        $this->state->selectionPanel->setItems($this->state->itemMenu->getRegularItems());

        if ($this->state->selectionPanel->totalItems === 0) {
          alert("You have no items left.");
          $this->state->setMode(new SelectIemMenuCommandMode($this->state));
        }
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
