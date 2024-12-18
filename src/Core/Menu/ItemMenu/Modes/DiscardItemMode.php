<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Exception;
use Ichiloto\Engine\Core\Menu\ItemMenu\Modes\ItemMenuMode;
use Ichiloto\Engine\IO\Enumerations\AxisName;
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

    if (Input::isButtonDown("confirm")) {
      if (confirm("Are you sure you want to discard this item?")) {
        $this->state->getGameScene()->party->inventory->removeItems($this->state->selectionPanel->activeItem);
        $this->state->selectionPanel->setItems($this->state->getGameScene()->party->inventory->all->toArray());

        if ($this->state->selectionPanel->activeIndex > $this->state->selectionPanel->totalItems - 1) {
          $this->state->selectionPanel->selectPrevious();
        }

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