<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Windows;

use Ichiloto\Engine\Core\Interfaces\CanFocus;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * The window that displays the commands that can be executed on an item.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu\Windows
 */
class ItemSelectionPanel extends Window implements CanFocus
{
  /**
   * The minimum number of pages.
   */
  public const int MIN_PAGE_COUNT = 1;
  /** Legacy display bound, retained for compatibility; pages are no longer truncated. */
  public const int MAX_PAGE_COUNT = 99;
  /**
   * @var int The index of the active item.
   */
  public int $activeIndex = -1 {
    get {
      return $this->activeIndex;
    }

    set {
      $this->activeIndex = max(-1, min($value, $this->totalItems - 1));
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
  protected(set) array $items = [];
  /**
   * @var int The total number of items.
   */
  protected(set) int $totalItems = 0;
  /**
   * @var int The height of the window.
   */
  public int $page {
    get {
      return intdiv(max(0, $this->activeIndex), $this->pageSize) + 1;
    }
  }
  /**
   * @var int The total number of pages.
   */
  public int $totalPages {
    get {
      return max(self::MIN_PAGE_COUNT, (int)ceil($this->totalItems / $this->pageSize));
    }
  }

  public int $pageSize { get => max(1, $this->height - 2); }

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
      "Page 1/1",
      'Left/Right: Change page',
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
    $this->activeIndex = max(0, $this->activeIndex);
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
    if ($this->totalItems === 0) { return; }
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
    if ($this->totalItems === 0) { return; }
    $nextIndex = wrap($this->activeIndex + 1, 0, $this->totalItems - 1);
    $this->activeIndex = $nextIndex;
    $this->updateInfoPanel();
  }

  public function changePage(int $direction): void
  {
    if ($this->totalItems === 0 || $direction === 0) { return; }
    $page = wrap($this->page - 1 + ($direction <=> 0), 0, $this->totalPages - 1);
    $offset = max(0, $this->activeIndex) % $this->pageSize;
    $this->activeIndex = min($this->totalItems - 1, $page * $this->pageSize + $offset);
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
    $this->items = array_values($items);
    $this->totalItems = count($items);
    // Reapply the owner index through its clamp after filtering or stack depletion.
    $this->activeIndex = $this->activeIndex;
  }

  /**
   * Updates the content of the window.
   *
   * @return void
   */
  public function updateContent(): void
  {
    $content = array_fill(0, $this->pageSize, '');
    $first = ($this->page - 1) * $this->pageSize;
    $this->title = sprintf("Page %02d/%02d", $this->page, $this->totalPages);

    foreach (array_slice($this->items, $first, $this->pageSize) as $row => $item) {
      $index = $first + $row;
      $prefix = $index === $this->activeIndex ? '>' : ' ';
      $itemName = TerminalText::padRight($item->name, 60);
      $quantity = TerminalText::padLeft((string)$item->quantity, 2);
      $content[$row] = " {$prefix} {$itemName} {$quantity}";
    }

    $this->setContent($content);
    $this->render();
  }

  /**
   * @return void
   */
  protected function updateInfoPanel(): void
  {
    if ($this->activeItem) {
      $this->state->infoPanel?->setText($this->activeItem->description);
    }
  }
}
