<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Windows;

use Ichiloto\Engine\Core\Interfaces\CanFocus;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Debug;

/**
 * The window that displays the commands that can be executed on an item.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu\Windows
 */
class ItemCommandPanel extends Window implements CanFocus
{
  /**
   * ItemMenuCommandsPanel constructor.
   *
   * @param MenuInterface $menu The menu that this window belongs to.
   * @param Rect $area The area of the window.
   * @param BorderPackInterface $borderPack The border pack.
   */
  public function __construct(
    protected MenuInterface $menu,
    Rect $area,
    BorderPackInterface $borderPack
  )
  {
    parent::__construct(
      'Item',
      '',
      $area->position,
      $area->size->width,
      $area->size->height,
      $borderPack
    );

    $this->menu->setActiveItemByIndex(0);
    $this->updateContent();
  }

  /**
   * @inheritdoc
   */
  public function focus(): void
  {
    // Do nothing
  }

  /**
   * @inheritdoc
   */
  public function blur(): void
  {
    // Do nothing
  }

  /**
   * Selects the previous item in the menu.
   *
   * @return void
   */
  public function selectPrevious(): void
  {
    $nextIndex = wrap($this->menu->activeIndex - 1, 0, $this->menu->getItems()->count() - 1);
    $this->menu->setActiveItemByIndex($nextIndex);
    $this->updateContent();
  }

  /**
   * Selects the next item in the menu.
   *
   * @return void
   */
  public function selectNext(): void
  {
    $nextIndex = wrap($this->menu->activeIndex + 1, 0, $this->menu->getItems()->count() - 1);
    $this->menu->setActiveItemByIndex($nextIndex);
    $this->updateContent();
  }

  /**
   * Updates the content of the window.
   *
   * @return void
   */
  public function updateContent(): void
  {
    $content = '';
    /** @var MenuItem $item */
    foreach ($this->menu->getItems() as $index => $item) {
      $prefix = $index === $this->menu->activeIndex ? '>' : ' ';
      $content .= sprintf(" %s %-12s", $prefix, $item->getLabel());
    }

    if (!is_iterable($content)) {
      $content = [$content];
    }

    $this->setContent($content);
    $this->render();
  }
}