<?php

namespace Ichiloto\Engine\Core\Menu\ShopMenu\Windows;

use Ichiloto\Engine\Core\Menu\ShopMenu\ShopMenu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * The main panel of the shop menu.
 *
 * @package Ichiloto\Engine\Core\Menu\ShopMenu\Windows
 */
class ShopMainPanel extends Window
{
  /**
   * @var InventoryItem[] The items to display in the shop menu.
   */
  protected(set) array $items = [];

  /**
   * Create a new instance of the shop main panel.
   *
   * @param ShopMenu $shopMenu The shop menu.
   * @param Rect $area The area of the window.
   * @param BorderPackInterface $borderPack The border pack to use.
   */
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
   * Set the items to display in the shop menu.
   *
   * @param InventoryItem[] $items The items to display.
   * @return void
   */
  public function setItems(array $items): void
  {
    $this->items = $items;
    $this->updateContent();
  }

  /**
   * Update the content of the shop menu.
   *
   * @return void
   */
  public function updateContent(): void
  {
    $content = [];

    foreach ($this->items as $index => $item) {
      $prefix = $index === $this->shopMenu->activeIndex ? '>' : ' ';
      $content[] = sprintf(" %s %-38s %10s", $prefix, $item->name, ":{$item->price}");
    }

    $content = array_pad($content, $this->height - 2, '');
    $this->setContent($content);
    $this->render();
  }
}