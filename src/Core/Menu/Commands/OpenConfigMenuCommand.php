<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Exception;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MainMenu\Modes\MainMenuConfigMode;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;

/**
 * OpenConfigMenuCommand. This class represents a menu item that opens the config menu.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class OpenConfigMenuCommand extends MenuItem
{
  /**
   * OpenConfigMenuCommand constructor.
   *
   * @param MenuInterface $menu The menu that this command belongs to.
   */
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Config', 'Change the game settings.');
  }

  /**
   * @inheritDoc
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    $state = $context?->args['state'] ?? throw new Exception('State not found in context.');

    if (! $state instanceof MainMenuState) {
      throw new Exception('Invalid state');
    }

    $state->setMode(new MainMenuConfigMode($state));

    return self::SUCCESS;
  }
}
