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
        1 => new SortItemsMode($this->state),
        2 => new DiscardItemMode($this->state),
        3 => new ViewKeyItemsMode($this->state),
        default => new UseItemMode($this->state),
      };
      $this->state->setMode($mode);
    }
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->updateSelectionPanelItems();
    $this->updateInfoPanel();
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
      $this->state->selectionPanel->setItems($scene->party->inventory->items->toArray());
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