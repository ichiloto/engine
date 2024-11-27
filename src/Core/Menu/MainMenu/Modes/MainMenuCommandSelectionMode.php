<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Modes;

use Ichiloto\Engine\Core\Menu\MainMenu\MainMenu;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;

/**
 * The MainMenuCommandSelectionMode class. Represents the main menu command selection mode.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Modes
 */
class MainMenuCommandSelectionMode extends MainMenuMode
{
  protected int $totalItems = 0;

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      $index = $this->getActiveIndex();
      if ($v > 0) {
        $index = wrap($index + 1, 0, $this->totalItems - 1);
      }

      if ($v < 0) {
        $index = wrap($index - 1, 0, $this->totalItems - 1);
      }

      $this->getMainMenu()->setActiveItemByIndex($index);
      $this->getMainMenu()->updateWindowContent();

      // Update info panel
      $this->mainMenuState->infoPanel->setText($this->getMainMenu()->getActiveItem()->getDescription());
    }

    if (Input::isAnyKeyPressed([KeyCode::ENTER])) {
      $this->getMainMenu()->getActiveItem()->execute();
    }
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->totalItems = $this->getMainMenu()->getItems()->count();
    $this->getMainMenu()->focus();
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->getMainMenu()->blur();
  }

  /**
   * Returns the main menu.
   *
   * @return MainMenu The main menu.
   */
  private function getMainMenu(): MainMenu
  {
    return $this->mainMenuState->mainMenu;
  }

  private function getActiveIndex(): int
  {
    return $this->mainMenuState->mainMenu->activeIndex;
  }
}