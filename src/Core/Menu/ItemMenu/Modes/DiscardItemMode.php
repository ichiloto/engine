<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Exception;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\UI\Modal\ModalManager;

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
      if (($item = $this->state->selectionPanel->activeItem) !== null) { $this->confirmDiscard($item); }
      return;
    }
  }

  private function confirmDiscard(InventoryItem $item): void
  {
    try {
      if (!$this->hasCurrentStack($item, 1)) { return; }
      $quantity = $item->quantity > 1
        ? ModalManager::getInstance($this->state->getGameScene()->getGame())->selectQuantity(
          'Discard ' . $item->name, $item->quantity, 'Discard item')
        : 1;
      if ($quantity === null || !$this->hasCurrentStack($item, $quantity)) { return; }
      if (!confirm(sprintf('Discard %s x %d?', $item->name, $quantity))
        || !$this->hasCurrentStack($item, $quantity)) { return; }
      if (!$this->inventory->consumeQuantityById($item->id, $quantity)) { return; }
      QuestManager::current()?->syncCollectObjectives();
      $this->state->selectionPanel->setItems($this->state->itemMenu->getRegularItems());
      if ($this->state->selectionPanel->totalItems === 0) {
        alert('You have no items left.');
        $this->state->setMode(new SelectIemMenuCommandMode($this->state));
      }
    } finally {
      InputManager::resetState();
      if ($this->state->mode === $this && !$this->state->getGameScene()->getGame()->hasStopped()) {
        $this->state->selectionPanel->setItems($this->state->itemMenu->getRegularItems());
        $this->state->infoPanel->setText($this->state->selectionPanel->activeItem?->description ?? '');
      }
    }
  }

  private function hasCurrentStack(InventoryItem $item, int $quantity): bool
  {
    if ($quantity < 1 || $item->quantity < $quantity
      || !in_array($item, $this->state->itemMenu->getRegularItems(), true)) {
      alert('The selected item quantity is no longer available.');
      return false;
    }
    return true;
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
