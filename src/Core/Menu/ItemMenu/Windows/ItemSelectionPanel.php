<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Windows;

use Ichiloto\Engine\Core\Interfaces\CanFocus;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Debug;

/**
 * The window that displays the commands that can be executed on an item.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu\Windows
 */
class ItemSelectionPanel extends Window implements CanFocus
{
  /**
   * @var int The index of the active item.
   */
  public int $activeIndex {
    get {
      return $this->activeIndex;
    }

    set {
      $this->activeIndex = $value;
      $this->updateContent();
    }
  }

  /**
   * @var InventoryItem|null The active item.
   */
  public ?InventoryItem $activeItem {
    get {
      return $this->items[$this->activeIndex] ?? null;
    }
  }

  /**
   * @var InventoryItem[] The items to display.
   */
  protected array $items = [];
  /**
   * @var int The total number of items.
   */
  protected(set) int $totalItems = 0;

  /**
   * ItemMenuCommandsPanel constructor.
   *
   * @param ItemMenuState $state The state of the item menu.
   * @param Rect $area The area of the window.
   * @param BorderPackInterface $borderPack The border pack.
   */
  public function __construct(
    protected ItemMenuState $state,
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
    $this->activeIndex = -1;
    $this->updateContent();
  }
  /**
   * @inheritDoc
   */
  public function focus(): void
  {
    $this->activeIndex = 0;
    $this->updateInfoPanel();
  }

  /**
   * @inheritDoc
   */
  public function blur(): void
  {
    $this->activeIndex = -1;
  }

  /**
   * Selects the previous item in the menu.
   *
   * @return void
   */
  public function selectPrevious(): void
  {
    $nextIndex = wrap($this->activeIndex - 1, 0, $this->totalItems - 1);
    $this->activeIndex = $nextIndex;
    $this->updateInfoPanel();
  }

  /**
   * Selects the next item in the menu.
   *
   * @return void
   */
  public function selectNext(): void
  {
    $nextIndex = wrap($this->activeIndex + 1, 0, $this->totalItems - 1);
    $this->activeIndex = $nextIndex;
    $this->updateInfoPanel();
  }

  /**
   * Sets the items to display in the menu.
   *
   * @param InventoryItem[] $items The items to display.
   * @return void
   */
  public function setItems(array $items): void
  {
    $this->items = $items;
    $this->totalItems = count($items);
    $this->updateContent();
  }

  /**
   * Updates the content of the window.
   *
   * @return void
   */
  protected function updateContent(): void
  {
    $content = array_fill(0, $this->height - 2, '');

    foreach ($this->items as $index => $item) {
      $prefix = $index === $this->activeIndex ? '>' : ' ';
      $content[$index] = sprintf(" %s %-32s:%2d", $prefix, $item->name, $item->quantity);
    }

    $this->setContent($content);
    $this->render();
  }

  /**
   * @return void
   */
  public function updateInfoPanel(): void
  {
    if ($this->activeItem) {
      $this->state->infoPanel->setText($this->activeItem->description);
    }
  }
}