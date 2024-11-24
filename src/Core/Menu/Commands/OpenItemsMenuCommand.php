<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;

/**
 * OpenItemsMenuCommand. This class represents a menu item that opens the items menu.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class OpenItemsMenuCommand extends MenuItem
{
  /**
   * OpenItemsMenuCommand constructor.
   *
   * @param MenuInterface $menu The menu that this command belongs to.
   */
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Items', "View items in the party's possession.");
  }
}