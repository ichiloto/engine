<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Exception;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MainMenu\Modes\MainMenuCharacterSelectionMode;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Util\Debug;

/**
 * Represents the command to open the abilities menu.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class OpenAbilityMenuCommand extends MenuItem
{
  /**
   * @param MenuInterface $menu The menu that this command belongs to.
   */
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Abilities', "View a character's abilities.");
  }

  /**
   * Opens the character-focused ability menu flow.
   *
   * @param ExecutionContextInterface|null $context The command execution context.
   * @return int The command result.
   * @throws Exception Thrown when the main-menu state is unavailable.
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
    $nextMode->nextGameSceneState = $context->scene->abilityMenuState;
    $state->setMode($nextMode);

    return self::SUCCESS;
  }
}
