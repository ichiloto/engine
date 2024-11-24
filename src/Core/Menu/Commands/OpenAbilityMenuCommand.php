<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;

class OpenAbilityMenuCommand extends MenuItem
{
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Abilities', "View a character's abilities.");
  }
}