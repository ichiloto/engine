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

    $nextMode = new MainMenuCharacterSelectionMode($state);
    $nextMode->nextGameSceneState = new EquipmentMenuState($state->context);
    $state->setMode($nextMode);

    return self::SUCCESS;
  }
}