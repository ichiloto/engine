<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Exception;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MainMenu\Modes\MainMenuCharacterSelectionMode;
use Ichiloto\Engine\Core\Menu\MainMenu\Modes\MainMenuMode;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\EquipmentMenuState;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Util\Debug;

/**
 * Represents the command to open the equipment menu.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class OpenEquipmentMenuCommand extends MenuItem
{
  /**
   * Initializes a new instance of the OpenEquipmentMenuCommand class.
   *
   * @param MenuInterface $menu The menu to which the command belongs.
   */
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Equipment', "View a character's equipment.");
  }

  /**
   * @inheritdoc
   * @throws Exception Thrown when the state is not found or is invalid.
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    $state = $context->args['state'] ?? throw new Exception('State not found');

    if (! $state instanceof MainMenuState) {
      throw new Exception('Invalid state');
    }

    if (! $context instanceof MenuCommandExecutionContext) {
      Debug::error("The context is null: " . __METHOD__);
      Debug::error(debug_get_backtrace());
      return self::FAILURE;
    }

    if (! $context->scene instanceof GameScene) {
      Debug::error("The scene is not a game scene: " . __METHOD__);
      Debug::error(debug_get_backtrace());
      return self::FAILURE;
    }

    $nextMode = new MainMenuCharacterSelectionMode($state);
    $nextMode->nextGameSceneState = $context->scene->equipmentMenuState;
    $state->setMode($nextMode);

    return self::SUCCESS;
  }
}