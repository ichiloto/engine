<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Modes;

use Ichiloto\Engine\Core\Menu\Interfaces\MainMenuModeInterface;
use Ichiloto\Engine\Scenes\Game\States\GameSceneState;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;

/**
 * MainMenuMode is an abstract class that represents a main menu mode.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Modes
 */
abstract class MainMenuMode implements MainMenuModeInterface
{
  /**
   * @var GameSceneState|null The next game scene state.
   */
  public ?GameSceneState $nextGameSceneState = null;

  /**
   * MainMenuMode constructor.
   *
   * @param MainMenuState $mainMenuState The main menu state.
   */
  public function __construct(protected MainMenuState $mainMenuState)
  {
  }
}