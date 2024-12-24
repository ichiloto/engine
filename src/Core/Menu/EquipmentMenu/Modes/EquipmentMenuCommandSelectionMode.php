<?php

namespace Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes;

use Ichiloto\Engine\Core\Menu\EquipmentMenu\Modes\EquipmentMenuMode;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Util\Debug;

class EquipmentMenuCommandSelectionMode extends EquipmentMenuMode
{
  /**
   * @var int The total number of commands.
   */
  protected int $totalCommands {
    get {
      return $this->state->equipmentMenu->getItems()->count();
    }
  }
  /**
   * @var bool Whether the active menu item is the equip command.
   */
  protected bool $activeMenuItemIsEquipCommand {
    get {
      return $this->state->equipmentMenu->activeIndex === 0 ;
    }
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    if (Input::isButtonDown("back")) {
      $this->state->setState(new MainMenuState($this->state->context));
    }

    if (Input::isButtonDown("confirm")) {
      if ($this->activeMenuItemIsEquipCommand) {
        $this->state->setMode(new EquipmentSlotSelectionMode($this->state));
      } else {
        $this->state->equipmentMenu->getActiveItem()->execute($this->state->equipmentMenuContext);
      }
    }

    $h = Input::getAxis(AxisName::HORIZONTAL);

    if (abs($h) > 0) {
      if ($h > 0) {
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
    $this->state->equipmentMenu->setActiveItemByIndex(0);
    $this->state->equipmentInfoPanel->setText($this->state->activeMenuCommand->getDescription());
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    // TODO: Implement exit() method.
  }

  /**
   * Selects the previous item in the list of commands.
   *
   * @return void
   */
  protected function selectPrevious(): void
  {
    $index = wrap($this->state->equipmentMenu->activeIndex - 1, 0, $this->totalCommands - 1);
    $this->state->equipmentMenu->setActiveItemByIndex($index);
    $this->state->equipmentCommandPanel->updateContent();
    $this->state->equipmentInfoPanel->setText($this->state->activeMenuCommand->getDescription());
  }

  /**
   * Selects the next item in the list of commands.
   *
   * @return void
   */
  protected function selectNext(): void
  {
    $index = wrap($this->state->equipmentMenu->activeIndex + 1, 0, $this->totalCommands - 1);
    $this->state->equipmentMenu->setActiveItemByIndex($index);
    $this->state->equipmentCommandPanel->updateContent();
    $this->state->equipmentInfoPanel->setText($this->state->activeMenuCommand->getDescription());
  }
}