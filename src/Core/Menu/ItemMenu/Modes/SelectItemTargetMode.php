<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Exception;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Menu\QuantitySelector;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;

/**
 * Class SelectItemTargetMode. Represents the target selection mode of the item menu.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu\Modes
 */
class SelectItemTargetMode extends ItemMenuMode
{
  /**
   * @var ItemMenuMode|null The previous mode.
   */
  public ?ItemMenuMode $previousMode = null;

  /** Active Shop-style quantity selection, when the chosen stack has multiple copies. */
  protected ?QuantitySelector $quantitySelector = null;
  protected ?InventoryItem $pendingItem = null;
  protected ?Character $pendingTarget = null;

  /**
   * @inheritDoc
   * @throws Exception If an error occurs while alerting the player.
   */
  public function update(): void
  {
    if ($this->quantitySelector instanceof QuantitySelector) {
      $this->updateQuantitySelection();
      return;
    }

    if (Input::isButtonDown("back")) {
      play_sound(SystemSound::CANCEL);
      $this->goBackToThePreviousMode();
      return;
    }

    if (Input::isButtonDown("confirm")) {
      if (($item = $this->state->selectionPanel->activeItem) && ($target = $this->state->targetSelectionPanel->activeCharacter)) {
        play_sound(SystemSound::CONFIRM);
        if ($item->quantity > 1) {
          $this->beginQuantitySelection($item, $target);
        } else {
          $this->useItem($item, $target, 1);
        }
      } else {
        play_sound(SystemSound::BUZZER);
      }

      return;
    }

    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      play_sound(SystemSound::CURSOR);

      if ($v > 0) {
        $this->selectNext();
      } else {
        $this->selectPrevious();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->state->targetSelectionPanel->setTargets($this->state->getGameScene()->party->members->toArray());
    $this->state->targetSelectionPanel->focus();
    $this->state->statusPanel->setTarget($this->state->targetSelectionPanel->activeCharacter);
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->cancelQuantitySelection();
    $this->state->targetSelectionPanel->setTargets([]);
    $this->state->targetSelectionPanel->blur();
    $this->state->statusPanel->setTarget(null);
  }

  /**
   * Selects the previous target.
   *
   * @return void
   */
  public function selectPrevious(): void
  {
    $activeIndex = $this->state->targetSelectionPanel->activeIndex - 1;
    $this->state->targetSelectionPanel->setActiveItemIndex(wrap($activeIndex, 0, $this->state->targetSelectionPanel->totalTargets - 1));
    $this->state->statusPanel->setTarget($this->state->targetSelectionPanel->activeCharacter);
  }

  /**
   * Selects the next target.
   *
   * @return void
   */
  public function selectNext(): void
  {
    $activeIndex = $this->state->targetSelectionPanel->activeIndex + 1;
    $this->state->targetSelectionPanel->setActiveItemIndex(wrap($activeIndex, 0, $this->state->targetSelectionPanel->totalTargets - 1));
    $this->state->statusPanel->setTarget($this->state->targetSelectionPanel->activeCharacter);
  }

  /**
   * Goes back to the previous mode.
   *
   * @return void
   */
  protected function goBackToThePreviousMode(): void
  {
    $previousMode = new SelectIemMenuCommandMode($this->state);
    if ($this->previousMode) {
      $previousMode = $this->previousMode;
    }

    $this->state->setMode($previousMode);
  }

  /** Starts the same bounded fine/coarse quantity workflow used by shops. */
  protected function beginQuantitySelection(InventoryItem $item, Character $target): void
  {
    $this->pendingItem = $item;
    $this->pendingTarget = $target;
    $this->quantitySelector = new QuantitySelector(maximum: $item->quantity);
    $this->updateQuantitySelectionContent();
  }

  /** Handles Shop-style quantity adjustment, confirmation, and cancellation. */
  protected function updateQuantitySelection(): void
  {
    if (
      Input::isButtonDown('cancel')
      || Input::isButtonDown('back')
    ) {
      play_sound(SystemSound::CANCEL);
      $this->cancelQuantitySelection();
      return;
    }

    $vertical = Input::getAxis(AxisName::VERTICAL);
    $horizontal = Input::getAxis(AxisName::HORIZONTAL);

    if (abs($vertical) > 0 || abs($horizontal) > 0) {
      if ($this->quantitySelector?->adjustForAxes($vertical, $horizontal)) {
        play_sound(SystemSound::CURSOR);
        $this->updateQuantitySelectionContent();
      }
      return;
    }

    if (! Input::isButtonDown('confirm')) {
      return;
    }

    $item = $this->pendingItem;
    $target = $this->pendingTarget;
    $quantity = $this->quantitySelector?->quantity ?? 1;
    $this->clearQuantitySelection();

    if ($item instanceof InventoryItem && $target instanceof Character) {
      play_sound(SystemSound::CONFIRM);
      $this->useItem($item, $target, $quantity);
    }
  }

  /** Renders the selected quantity in the existing item-menu information panel. */
  protected function updateQuantitySelectionContent(): void
  {
    if (
      ! $this->quantitySelector instanceof QuantitySelector
      || ! $this->pendingItem instanceof InventoryItem
      || ! $this->pendingTarget instanceof Character
    ) {
      return;
    }

    $this->state->infoPanel->setText(sprintf(
      "Use %s x %02d on %s?\nUp/Down: +1/-1  Right/Left: +10/-10  Enter: Confirm  C/Esc: Cancel",
      $this->pendingItem->name,
      $this->quantitySelector->quantity,
      $this->pendingTarget->name,
    ));
  }

  /** Cancels quantity selection without consuming the item. */
  protected function cancelQuantitySelection(): void
  {
    if (! $this->quantitySelector instanceof QuantitySelector) {
      return;
    }

    $this->clearQuantitySelection();
    $this->state->infoPanel->setText(
      $this->state->selectionPanel->activeItem?->description ?? ''
    );
  }

  /** Clears transient quantity state. */
  protected function clearQuantitySelection(): void
  {
    $this->quantitySelector = null;
    $this->pendingItem = null;
    $this->pendingTarget = null;
  }

  /** Applies the selected quantity and refreshes the existing item panels. */
  protected function useItem(InventoryItem $item, Character $target, int $quantity): void
  {
    $target->use($item, $quantity);

    if ($item->quantity === 0) {
      $this->inventory->removeItems($item);
      $this->state->selectionPanel->setItems($this->inventory->items->toArray());
    }

    $this->state->statusPanel->updateContent();
    $this->state->selectionPanel->updateContent();
    $this->state->infoPanel->setText(
      $this->state->selectionPanel->activeItem?->description ?? ''
    );
  }
}
