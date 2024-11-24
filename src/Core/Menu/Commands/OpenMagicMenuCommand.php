<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;

/**
 * OpenMagicMenuCommand. This class represents a menu item that opens the magic menu.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class OpenMagicMenuCommand extends MenuItem
{
  /**
   * OpenMagicMenuCommand constructor.
   *
   * @param MenuInterface $menu The menu that this command belongs to.
   */
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Magic', "View a character's magic.");
  }
}