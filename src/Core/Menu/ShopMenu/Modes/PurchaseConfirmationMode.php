<?php

namespace Ichiloto\Engine\Core\Menu\ShopMenu\Modes;

use Exception;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * Represents the purchase confirmation mode.
 *
 * @package Ichiloto\Engine\Core\Menu\ShopMenu\Modes
 */
class PurchaseConfirmationMode extends ShopMenuMode
{
  public ?InventoryItem $item = null;
  /**
   * @var ShopMenuMode|null The previous mode.
   */
  public ?ShopMenuMode $previousMode = null;
  /**
   * @var int The quantity of the item to purchase.
   */
  public int $quantity = 1 {
    get {
      return $this->quantity;
    }

    set {
      $max = $this->maxQuantity - $this->state->detailPanel->possession;
      $this->quantity = clamp($value, 1, $max);
    }
  }
  /**
   * @var int The maximum quantity of the item to purchase.
   */
  protected(set) int $maxQuantity = 99;
  /**
   * @var int The total price of the purchase.
   */
  protected int $totalPrice {
    get {
      $total = ($this->item?->price ?? 0) * $this->quantity;

      if ($this->previousMode instanceof ShopInventorySelectionMode) {
        return $total * .5;
      }

      return $total;
    }
  }
  protected string $symbol = 'G';
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
   * @throws Exception
   */
  public function update(): void
  {
    $this->handleNavigation();
    $this->handleActions();
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->state->mainPanel->setHelp('esc:Cancel, enter:Confirm');
    $this->state->infoPanel->setText('Use the arrow keys to adjust the quantity of the item to purchase.');
    $this->quantity = 1;
    $this->symbol = config(ProjectConfig::class, 'vocab.currency.symbol', 'G');
    $this->updateWindowContent();
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->state->mainPanel->setHelp('');
  }

  /**
   * Handles navigation.
   *
   * @return void
   */
  protected function handleNavigation(): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);
    $h = Input::getAxis(AxisName::HORIZONTAL);

    if (abs($v) > 0 || abs($h) > 0) {
      if ($v > 0) {
        $this->decreaseQuantity();
      }
      if ($v < 0) {
        $this->increaseQuantity();
      }

      if ($h > 0) {
        $this->increaseQuantity(10);
      }
      if ($h < 0) {
        $this->decreaseQuantity(10);
      }

      $this->updateWindowContent();
    }
  }

  /**
   * @throws Exception
   */
  protected function handleActions(): void
  {
    if (Input::isButtonDown("cancel")) {
      if ($this->previousMode) {
        $this->state->setMode($this->previousMode);
      }
    }

    if (Input::isButtonDown("confirm")) {
      $this->completeCheckout();
      $this->state->setMode($this->previousMode);
    }
  }

  /**
   * Increases the quantity of the item to purchase.
   *
   * @param int $amount The amount to increase the quantity by.
   */
  protected function increaseQuantity(int $amount = 1): void
  {
    $futureQuantity = $this->quantity + $amount;

    if (
      $this->previousMode instanceof ShopMerchandiseSelectionMode &&
      $futureQuantity * $this->item->price > $this->party->accountBalance
    ) {
      return;
    }

    $this->quantity += $amount;
  }

  /**
   * Decreases the quantity of the item to purchase.
   *
   * @param int $amount The amount to decrease the quantity by.
   */
  protected function decreaseQuantity(int $amount = 1): void
  {
    $this->quantity -= $amount;
  }

  /**
   * Updates the window content.
   *
   * @return void
   */
  public function updateWindowContent(): void
  {
    $content = [
      "",
      sprintf(" %-45s x %2d", $this->item->name ?? 'N/A', $this->quantity),
      " -------------------------------------------------- ",
      sprintf(" %48d %s", $this->totalPrice, $this->symbol),
    ];
    $content = array_pad($content, $this->state->mainPanel->contentHeight, '');


    $this->state->mainPanel->setContent($content);
    $this->state->mainPanel->render();
  }

  /**
   * Completes the checkout process.
   *
   * @return void
   * @throws Exception If the previous mode is not an instance of ShopMerchandiseSelectionMode.
   */
  public function completeCheckout(): void
  {
    if ($this->previousMode instanceof ShopMerchandiseSelectionMode) {
      $this->state->shop->sell($this->item, $this->quantity, $this->party);
      $this->state->accountBalancePanel->setBalance($this->party->accountBalance);
      $this->state->mainPanel->setItems($this->state->merchandise);
      $this->previousMode->updateItemsInPossession();
    }

    if ($this->previousMode instanceof ShopInventorySelectionMode) {
      $this->state->shop->buy($this->item, $this->quantity, $this->party);
      $this->state->accountBalancePanel->setBalance($this->party->accountBalance);
      $this->state->mainPanel->setItems($this->state->inventory->all->toArray());
      $this->previousMode->updateItemsInPossession();
    }
  }
}