<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Exception;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Util\Debug;

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
      $this->goBackToThePreviousMode();
    }

    if (Input::isButtonDown("confirm")) {
      if (($item = $this->state->selectionPanel->activeItem) && ($target = $this->state->targetSelectionPanel->activeCharacter)) {
        $useQuantity = $this->getNumberOfUses($item);
        $target->use($item, $useQuantity);
        if ($item->quantity === 0) {
          $this->inventory->removeItems($item);
          $this->state->selectionPanel->setItems($this->inventory->items->toArray());
        }
        $this->goBackToThePreviousMode();
      }
    }

    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
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

  /**
   * Gets the quantity of the item to use.
   *
   * @param InventoryItem $item The item to use.
   * @return int The quantity of the item to use.
   * @throws Exception If an error occurs while prompting the player.
   */
  private function getNumberOfUses(InventoryItem $item): int
  {
    // TODO: Implement a way to prompt the player for the number of uses.
//    return (int)prompt("How many {$item->name} do you want to use?", 1);
    return 1;
  }
}