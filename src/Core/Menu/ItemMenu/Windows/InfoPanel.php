<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Windows;

use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\ItemMenu\ItemMenu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the item info panel window.
 *
 * @package Ichiloto\Engine\Core\Menu\ItemMenu\Windows
 */
class InfoPanel extends Window
{
  public function __construct(
    protected MenuInterface $menu,
    Rect                    $area,
    BorderPackInterface     $borderPack
  )
  {
    parent::__construct(
      'Info',
      '',
      $area->position,
      $area->size->width,
      $area->size->height,
      $borderPack
    );
  }

  /**
   * Updates the content of the window.
   *
   * @param string $text The text content to set.
   * @return void
   */
  public function setText(string $text): void
  {
    $lines = explode("\n", $text);
    $lineCount = count($lines);

    $lines = array_pad($lines, $this->height - 2, '');

    if ($lineCount > 2) {
      $lines = array_slice($lines, 0, 2);
    }

    $this->setContent($lines);
    $this->render();
  }
}