<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;

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
}