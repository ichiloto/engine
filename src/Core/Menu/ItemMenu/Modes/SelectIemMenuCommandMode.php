<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Modes;

use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * Class ItemMenuCommandSelectionMode. Represents the command selection mode of the item menu.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu\Modes
 */
class SelectIemMenuCommandMode extends ItemMenuMode
{
  /**
   * The index of the use items command.
   */
  protected const int USE_ITEMS_INDEX = 0;
  /**
   * The index of the sort items command.
   */
  protected const int SORT_ITEMS_INDEX = 1;
  /**
   * The index of the discard item command.
   */
  protected const int DISCARD_ITEM_INDEX = 2;
  /**
   * The index of the view key items command.
   */
  protected const int VIEW_KEY_ITEMS_INDEX = 3;

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $h = Input::getAxis(AxisName::HORIZONTAL);

    if (abs($h) > 0) {
      if ($h > 0) {
        $this->selectNextMode();
      } else {
        $this->selectPreviousMode();
      }
    }

    if (Input::isButtonDown("back")) {
      $scene = $this->state->context->getScene();

      if ($scene instanceof GameScene) {
        $scene->setState($scene->mainMenuState);
      }
    }

    if (Input::isButtonDown("confirm")) {
      $this->state->itemMenu->getActiveItem()?->execute($this->state->itemMenuContext);
      $mode = match ($this->state->itemMenu->activeIndex) {
        self::SORT_ITEMS_INDEX => new SortItemsMode($this->state),
        self::DISCARD_ITEM_INDEX => new DiscardItemMode($this->state),
        self::VIEW_KEY_ITEMS_INDEX => new ViewKeyItemsMode($this->state),
        default => new UseItemMode($this->state),
      };

      if ($mode instanceof SortItemsMode) {
        $mode->update();
      } else {
        $this->state->setMode($mode);
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->updateSelectionPanelItems();
    $this->updateInfoPanel();
    $this->state->selectionPanel->blur();
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    // TODO: Implement exit() method.
  }

  /**
   * Selects the next mode.
   *
   * @return void
   */
  protected function selectNextMode(): void
  {
    $this->state->itemMenuCommandsPanel->selectNext();
    $this->updateSelectionPanelItems();
    $this->updateInfoPanel();
  }

  /**
   * Selects the previous mode.
   *
   * @return void
   */
  protected function selectPreviousMode(): void
  {
    $this->state->itemMenuCommandsPanel->selectPrevious();
    $this->updateSelectionPanelItems();
    $this->updateInfoPanel();
  }

  /**
   * Updates the selection panel items.
   *
   * @return void
   */
  protected function updateSelectionPanelItems(): void
  {
    $scene = $this->state->context->getScene();

    if ($scene instanceof GameScene) {
      $items = $scene->party->inventory->items->toArray();
      if ($this->state->itemMenu->activeIndex === self::VIEW_KEY_ITEMS_INDEX) {
        $items = $scene->party->inventory->keyItems->toArray();
      }

      $this->state->selectionPanel->setItems($items);
    }
  }

  /**
   * Updates the info panel.
   *
   * @return void
   */
  protected function updateInfoPanel(): void
  {
    $this->state->infoPanel->setText($this->state->itemMenu->getActiveItem()->getDescription());
  }
}