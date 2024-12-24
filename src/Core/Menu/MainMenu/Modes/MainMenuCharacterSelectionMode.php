<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Modes;

use Ichiloto\Engine\Core\Menu\MainMenu\CharacterSelectionMenu;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\Game\States\EquipmentMenuState;
use Ichiloto\Engine\Util\Debug;
use RuntimeException;

/**
 * Represents the character selection mode.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Modes
 */
class MainMenuCharacterSelectionMode extends MainMenuMode
{
  /**
   * @var CharacterSelectionMenu The character selection menu.
   */
  protected CharacterSelectionMenu $characterSelectionMenu {
    get {
      return $this->mainMenuState->characterSelectionMenu;
    }
  }

  /**
   * @inheritDoc
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
    $this->characterSelectionMenu->focus();
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->characterSelectionMenu->blur();
    $this->mainMenuState->mainMenu->setActiveItemByIndex(2);
  }

  /**
   * Handles the navigation of the character selection menu.
   *
   * @return void
   */
  protected function handleNavigation(): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      if ($v > 0) {
        $this->characterSelectionMenu->selectNext();
      }

      if ($v < 0) {
        $this->characterSelectionMenu->selectPrevious();
      }
    }
  }

  /**
   * Handles the actions of the character selection menu.
   *
   * @return void
   */
  protected function handleActions(): void
  {
    if (Input::isButtonDown("cancel")) {
      $this->mainMenuState->setMode(new MainMenuCommandSelectionMode($this->mainMenuState));
    }

    if (Input::isButtonDown("confirm")) {
      if ($this->nextGameSceneState instanceof EquipmentMenuState) {
        $this->nextGameSceneState->character = $this->characterSelectionMenu->activeCharacter ?? throw new RuntimeException("Character not found.");
      }
      $this->mainMenuState->setState($this->nextGameSceneState);
    }
  }
}