<?php /** @noinspection DuplicatedCode */

namespace Ichiloto\Engine\Core\Menu\ShopMenu\Modes;

use Exception;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;

/**
 * Represents the shop inventory selection mode.
 *
 * @package Ichiloto\Engine\Core\Menu\ShopMenu\Modes
 */
class ShopInventorySelectionMode extends ShopMenuMode
{
  /**
   * @var ShopMenuMode|null The previous mode.
   */
  public ?ShopMenuMode $previousMode = null;

  /**
   * @var int The total inventory count.
   */
  public int $totalInventory {
    get {
      return $this->state->inventory->all->count();
    }
  }
  /**
   * @var InventoryItem|null The selected item.
   */
  public ?InventoryItem $selectedItem {
    get {
      return $this->state->inventory->all->toArray()[$this->state->mainPanel->activeItemIndex] ?? null;
    }
  }
  /**
   * @var Party The party.
   */
  protected Party $party {
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

      $this->state->infoPanel->setText($this->selectedItem?->description ?? '');
      $this->updateItemsInPossession();
    }
    if (Input::isButtonDown("back")) {
      $this->navigateToPreviousMode();
    }

    if (Input::isButtonDown("confirm")) {
      if ($this->selectedItem) {
        $purchaseConfirmationMode = new PurchaseConfirmationMode($this->state);
        $purchaseConfirmationMode->previousMode = $this;
        $purchaseConfirmationMode->item = $this->selectedItem;

        $this->state->setMode($purchaseConfirmationMode);
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
    $this->state->mainPanel->setItems($this->state->inventory->all->toArray());
    $this->state->mainPanel->activeItemIndex = 0;
    $this->updateItemsInPossession();
    $this->state->infoPanel->setText($this->selectedItem->description);
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
    if ($activeItem = $this->state->mainPanel->activeItem) {
      $this->state->detailPanel->possession = 0;

      if ($inventoryItem = $this->state->inventory->all->find(fn(InventoryItem $item) => $item->name === $activeItem->name) ) {
        $this->state->detailPanel->possession = $inventoryItem->quantity ?? 0;
      }
      $this->state->detailPanel->updateContent();
    }
  }
}