<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;

/**
 * OpenPartyOrderCommand. This class represents a menu item that opens the party order menu.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class OpenPartyOrderCommand extends MenuItem
{
  /**
   * OpenPartyOrderCommand constructor.
   *
   * @param MenuInterface $menu The menu that this command belongs to.
   */
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Order', 'Change the order of the party members.');
  }
}