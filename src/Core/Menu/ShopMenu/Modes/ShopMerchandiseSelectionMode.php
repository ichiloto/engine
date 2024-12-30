<?php

namespace Ichiloto\Engine\Core\Menu\ShopMenu\Modes;

use Exception;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Util\Debug;

/**
 * Represents the shop item selection mode.
 *
 * @package Ichiloto\Engine\Core\Menu\ShopMenu\Modes
 */
class ShopMerchandiseSelectionMode extends ShopMenuMode
{
  /**
   * @var ShopMenuMode|null The previous mode.
   */
  public ?ShopMenuMode $previousMode = null;
  /**
   * @var InventoryItem|null The selected item.
   */
  public ?InventoryItem $selectedItem {
    get {
      return $this->state->merchandise[$this->state->mainPanel->activeItemIndex] ?? null;
    }
  }

  /**
   * @var Party The party.
   */
  public Party $party {
    get {
      return $this->state->getGameScene()->party;
    }
  }

  /**
   * @inheritDoc
   * @throws Exception If the previous mode is not set.
   */
  public function update(): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      if ($v > 0) {
        $this->selectNextItem();
      } else {
        $this->selectPreviousItem();
      }

      $this->updateItemsInPossession();
    }

    if (Input::isButtonDown("back")) {
      $this->navigateToPreviousMode();
    }

    if (Input::isButtonDown("confirm")) {
      if ($this->selectedItem) {
        $this->state->shop->sell($this->selectedItem, 1, $this->state->getGameScene()->party);
        $this->state->accountBalancePanel->setBalance($this->party->accountBalance);
        $this->state->mainPanel->setItems($this->state->merchandise);
        $this->updateItemsInPossession();
      } else {
        alert("No items.");
        $this->navigateToPreviousMode();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->state->mainPanel->setItems($this->state->merchandise);
    $this->updateItemsInPossession();
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    // Do nothing
  }

  /**
   * Selects the previous item.
   *
   * @return void
   */
  private function selectPreviousItem(): void
  {
    $this->state->mainPanel->selectPrevious();
  }

  /**
   * Selects the next item.
   *
   * @return void
   */
  private function selectNextItem(): void
  {
    $this->state->mainPanel->selectNext();
  }

  /**
   * Navigates to the previous mode.
   *
   * @return void
   */
  protected function navigateToPreviousMode(): void
  {
    if ($this->previousMode) {
      $this->state->detailPanel->clear();
      $this->state->commandPanel->startingIndex = 0;
      $this->state->setMode($this->previousMode);
    }
  }

  /**
   * Updates the items in possession.
   *
   * @return void
   */
  public function updateItemsInPossession(): void
  {
    Debug::log(__METHOD__);
    if ($activeItem = $this->state->mainPanel->activeItem) {
      Debug::log("Active item: " . $activeItem->name);
      $this->state->detailPanel->possession = 0;

      if ($inventoryItem = $this->state->inventory->all->find(fn(InventoryItem $item) => $item->name === $activeItem->name) ) {
        Debug::log("Item found in inventory: " . $inventoryItem->name);
        $this->state->detailPanel->possession = $inventoryItem->quantity ?? 0;
      }
      $this->state->detailPanel->updateContent();
    }
  }
}