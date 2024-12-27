<?php

namespace Ichiloto\Engine\Core\Menu\ShopMenu\Windows;

use Ichiloto\Engine\Core\Menu\ShopMenu\ShopMenu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

class ShopItemDetailPanel extends Window implements ObserverInterface
{
  public function __construct(
    protected ShopMenu $shopMenu,
    Rect $area,
    BorderPackInterface $borderPack
  )
  {
    parent::__construct(
    '',
    '',
      $area->position,
      $area->size->width,
      $area->size->height,
      $borderPack
    );
  }

  /**
   * @inheritDoc
   */
  public function onNotify(object $entity, EventInterface $event): void
  {
    // TODO: Implement onNotify() method.
  }
}