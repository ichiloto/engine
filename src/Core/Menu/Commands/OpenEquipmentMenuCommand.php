<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;

class OpenEquipmentMenuCommand extends MenuItem
{
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Equipment', "View a character's equipment.");
  }
}