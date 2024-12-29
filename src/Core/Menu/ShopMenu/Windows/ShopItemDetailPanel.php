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
  public int $possession = 0;

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

  /**
   * @return void
   */
  public function updateContent(): void
  {
    $content = [
      sprintf(" Possession %39d", $this->possession),
    ];

    $content = array_pad($content, $this->height - 2, '');
    $this->setContent($content);
    $this->render();
  }

  public function clear(): void
  {
    $this->setContent(array_fill(0, $this->height - 2, ''));
    $this->render();
  }
}