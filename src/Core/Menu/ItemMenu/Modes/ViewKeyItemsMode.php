<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Entities\Interfaces\InventoryItemInterface;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;

/**
 * Lists the party's key items.
 *
 * Key items are quest-critical possessions: they cannot be used, discarded,
 * or sold, so this mode is a browsable read-only view whose descriptions
 * tell the player why they are carrying each one.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu\Modes
 */
class ViewKeyItemsMode extends ItemMenuMode
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

    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      play_sound(SystemSound::CURSOR);

      if ($v > 0) {
        $this->state->selectionPanel->selectNext();
      } else {
        $this->state->selectionPanel->selectPrevious();
      }

      $this->describeActiveItem();
    }
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $keyItems = $this->inventory->keyItems->toArray();

    $this->state->selectionPanel->setItems($keyItems);
    $this->state->selectionPanel->focus();
    $this->state->infoPanel?->setText(
      empty($keyItems)
        ? 'No key items yet.'
        : 'Key items cannot be used, discarded, or sold.'
    );

    if (! empty($keyItems)) {
      $this->describeActiveItem();
    }
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    // Restore the full inventory for the modes that expect it.
    $this->state->selectionPanel->setItems($this->inventory->items->toArray());
    $this->state->selectionPanel->blur();
  }

  /**
   * Shows the active key item's description.
   *
   * @return void
   */
  protected function describeActiveItem(): void
  {
    $item = $this->state->selectionPanel->activeItem ?? null;

    if ($item instanceof InventoryItemInterface) {
      $this->state->infoPanel?->setText($item->description);
    }
  }
}
