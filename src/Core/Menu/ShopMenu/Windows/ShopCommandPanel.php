<?php

namespace Ichiloto\Engine\Core\Menu\ShopMenu\Windows;

use Ichiloto\Engine\Core\Menu\ShopMenu\ShopMenu;
use Ichiloto\Engine\UI\Windows\Window;

class ShopCommandPanel extends Window
{
  public function __construct(
    protected ShopMenu $shopMenu,
  )
  {

  }
}