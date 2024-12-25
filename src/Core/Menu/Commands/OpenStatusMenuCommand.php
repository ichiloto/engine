<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Exception;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MainMenu\Modes\MainMenuCharacterSelectionMode;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Scenes\Game\States\StatusViewState;

/**
 * OpenStatusMenuCommand. This class represents a menu item that opens the status menu.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class OpenStatusMenuCommand extends MenuItem
{
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Status', "View a character's status.");
  }

  /**
   * @inheritDoc
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    $state = $context->args['state'] ?? throw new Exception('State not found in context.');

    if (! $state instanceof MainMenuState) {
      throw new Exception('Invalid state');
    }

    $nextMode = new MainMenuCharacterSelectionMode($state);
    $nextMode->nextGameSceneState = new StatusViewState($state->context);
    $state->setMode($nextMode);

    return self::SUCCESS;
  }
}