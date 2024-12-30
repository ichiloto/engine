<?php

namespace Ichiloto\Engine\Core\Menu\ShopMenu\Windows;

use Ichiloto\Engine\Core\Menu\ShopMenu\ShopMenu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ProjectConfig;

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
   * @var int The total number of items in the shop menu.
   */
  protected(set) int $totalItems = 0;
  /**
   * @var int The index of the active item.
   */
  public int $activeItemIndex = 0 {
    get {
      return $this->activeItemIndex;
    }

    set {
      $this->activeItemIndex = $value;
      $this->updateContent();
    }
  }

  /**
   * @var InventoryItem|null The active item in the shop menu.
   */
  public ?InventoryItem $activeItem {
    get {
      return $this->items[$this->activeItemIndex] ?? null;
    }
  }

  public int $contentHeight {
    get {
      return $this->height - 2;
    }
  }

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
    $this->totalItems = count($this->items);
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
    $symbol = config(ProjectConfig::class, 'vocab.currency.symbol', 'F');

    foreach ($this->items as $index => $item) {
      $prefix = $index === $this->activeItemIndex ? '>' : ' ';
      $content[] = sprintf(" %s %-36s %10s", $prefix, $item->name, "{$item->price} {$symbol}");
    }

    $content = array_pad($content, $this->contentHeight, '');
    $this->setContent($content);
    $this->render();
  }

  /**
   * Select the next item in the shop menu.
   *
   * @return void
   */
  public function selectNext(): void
  {
    $index = wrap($this->activeItemIndex + 1, 0, $this->totalItems - 1);
    $this->activeItemIndex = $index;
  }

  /**
   * Select the previous item in the shop menu.
   *
   * @return void
   */
  public function selectPrevious(): void
  {
    $index = wrap($this->activeItemIndex - 1, 0, $this->totalItems - 1);
    $this->activeItemIndex = $index;
  }
}