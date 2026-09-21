<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Exception;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\UI\Modal\ModalManager;

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

  /**
   * @inheritDoc
   * @throws Exception If an error occurs while alerting the player.
   */
  public function update(): void
  {
    if (Input::isButtonDown("back")) {
      play_sound(SystemSound::CANCEL);
      $this->goBackToThePreviousMode();
      return;
    }

    if (Input::isButtonDown("confirm")) {
      if (($item = $this->state->selectionPanel->activeItem) && ($target = $this->state->targetSelectionPanel->activeCharacter)) {
        play_sound(SystemSound::CONFIRM);
        $this->confirmUse($item, $target);
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

  /** Blocking modals own their input; only this mode applies the confirmed amount. */
  protected function confirmUse(InventoryItem $item, Character $target): void
  {
    try {
      if (!$this->canUseCurrentStack($item, $target, 1)) { return; }
      $quantity = $item->quantity > 1
        ? ModalManager::getInstance($this->state->getGameScene()->getGame())->selectQuantity(
          sprintf('Use %s on %s', $item->name, $target->name), $item->quantity, 'Use item')
        : 1;
      if ($quantity === null || !$this->canUseCurrentStack($item, $target, $quantity)) { return; }
      if (confirm(sprintf('Use %s x %d on %s?', $item->name, $quantity, $target->name))
        && $this->canUseCurrentStack($item, $target, $quantity)) {
        $this->useItem($item, $target, $quantity);
      }
    } finally {
      InputManager::resetState();
      if ($this->state->mode === $this && !$this->state->getGameScene()->getGame()->hasStopped()) {
        $this->state->selectionPanel->setItems($this->state->itemMenu->getRegularItems());
        $this->state->infoPanel->setText($this->state->selectionPanel->activeItem?->description ?? '');
      }
    }
  }

  private function canUseCurrentStack(InventoryItem $item, Character $target, int $quantity): bool
  {
    if ($quantity < 1 || $item->quantity < $quantity
      || !in_array($item, $this->state->itemMenu->getRegularItems(), true)
      || !in_array($target, $this->party->members->toArray(), true)) {
      alert('The selected item quantity or target is no longer available.');
      return false;
    }
    return true;
  }

  /** Applies the selected quantity and refreshes the existing item panels. */
  protected function useItem(InventoryItem $item, Character $target, int $quantity): void
  {
    $target->use($item, $quantity);

    if ($item->quantity === 0) {
      $this->inventory->all->remove($item);
    }
    QuestManager::current()?->syncCollectObjectives();

    if ($this->state->getGameScene()->getGame()->hasStopped()) { return; }
    $this->state->statusPanel->updateContent();
  }
}
