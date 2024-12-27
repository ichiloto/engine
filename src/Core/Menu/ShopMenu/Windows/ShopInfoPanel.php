<?php

namespace Ichiloto\Engine\Core\Menu\ShopMenu\Windows;

use Ichiloto\Engine\Core\Menu\ShopMenu\ShopMenu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Represents the shop info panel.
 *
 * @package Ichiloto\Engine\Core\Menu\ShopMenu\Windows
 */
class ShopInfoPanel extends Window
{
  public function __construct(
    protected ShopMenu $shopMenu,
    Rect $area,
    BorderPackInterface $borderPack
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
   * Sets the text of the info panel.
   *
   * @param string $text The text to set.
   */
  public function setText(string $text): void
  {
    $content = explode("\n", $text);
    $this->setContent($content);
    $this->render();
  }
}