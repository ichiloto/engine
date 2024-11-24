<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;

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
}