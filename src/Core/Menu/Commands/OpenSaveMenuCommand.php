<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;

/**
 * OpenSaveMenuCommand. This class represents a menu item that opens the save menu.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class OpenSaveMenuCommand extends MenuItem
{
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Save', 'Create or overwrite a save file.');
  }
}