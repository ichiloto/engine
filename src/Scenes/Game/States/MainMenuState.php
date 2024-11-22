<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Scenes\SceneStateContext;

/**
 * The MainMenu state allows the player to access the in-game menu for managing inventory, checking the map, viewing stats, and saving the game.
 *
 * Feature:
 * - Inventory management.
 * - Party management (e.g., swapping characters or equipment).
 * - Accessing the map or quest log.
 * - Saving or loading the game.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class MainMenuState extends GameSceneState
{
  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->context->getScene()->camera->erase();

    // Display the main menu UI.
  }

  /**
   * @inheritDoc
   * @throws NotFoundException
   */
  public function execute(?SceneStateContext $context = null): void
  {
    if (InputManager::isAnyKeyPressed([KeyCode::ESCAPE])) {
      $this->setState($this->context->getScene()->fieldState ?? throw new NotFoundException('FieldState'));
    }
  }
}