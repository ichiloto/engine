<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Modes;

use Ichiloto\Engine\Core\Menu\MainMenu\MainMenu;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\InfoPanel;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Util\Debug;

/**
 * The MainMenuCommandSelectionMode class. Represents the main menu command selection mode.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Modes
 */
class MainMenuCommandSelectionMode extends MainMenuMode
{
  /**
   * The total items.
   *
   * @var int $totalItems
   */
  protected int $totalItems = 0;

  /**
   * @inheritDoc
   * @throws NotFoundException If the field state is not found.
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
    $this->totalItems = $this->getMainMenu()->getItems()->count();
    $this->getMainMenu()->setActiveItemByIndex($this->mainMenuState->startingIndex);
    $this->getMainMenu()->focus();
    $this->getMainMenu()->updateWindowContent();
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
   * @return MainMenu|null The main menu.
   */
  private function getMainMenu(): ?MainMenu
  {
    return $this->mainMenuState->mainMenu;
  }

  /**
   * Returns the active index.
   *
   * @return int The active index.
   */
  private function getActiveIndex(): int
  {
    return $this->mainMenuState->mainMenu->activeIndex;
  }

  /**
   * Returns the info panel.
   *
   * @return InfoPanel|null The info panel.
   */
  public function getInfoPanel(): ?InfoPanel
  {
    return $this->mainMenuState->infoPanel;
  }

  /**
   * Handles the navigation.
   *
   * @return void
   */
  protected function handleNavigation(): void
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

      $this->getMainMenu()?->setActiveItemByIndex($index);
      $this->getMainMenu()?->updateWindowContent();

      $this->getInfoPanel()?->setText($this->getMainMenu()?->getActiveItem()->getDescription());
    }
  }

  /**
   * Handles the actions.
   *
   * @return void
   * @throws NotFoundException
   */
  protected function handleActions(): void
  {
    if (Input::isButtonDown("cancel")) {
      $this->mainMenuState->startingIndex = 0;
      $this->mainMenuState->setState($this->mainMenuState->getGameScene()->fieldState ?? throw new NotFoundException('FieldState'));
    }

    if (Input::isButtonDown("confirm")) {
      $this->mainMenuState->startingIndex = $this->getMainMenu()->activeIndex;
      $this->getMainMenu()->getActiveItem()->execute($this->mainMenuState->mainMenuContext);
    }
  }
}