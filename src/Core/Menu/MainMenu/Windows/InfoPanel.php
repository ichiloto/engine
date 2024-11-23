<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu\Windows;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * InfoPanel is the window that displays information about the currently selected item and helpful tips.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu\Windows
 */
class InfoPanel extends Window
{
  /**
   * The width of the window.
   */
  protected const int WIDTH = 110;
  /**
   * The height of the window.
   */
  protected const int HEIGHT = 3;

  /**
   * InfoPanel constructor.
   *
   * @param Vector2 $position The position of the window.
   */
  public function __construct(
    Vector2 $position,
    BorderPackInterface $borderPack = new DefaultBorderPack()
  )
  {
    parent::__construct('Info', '', $position, self::WIDTH, self::HEIGHT, $borderPack);
  }

  /**
   * Sets the text of the window.
   *
   * @param string $text The text to set.
   * @return void
   */
  public function setText(string $text): void
  {
    $this->setContent([$text]);
    $this->render();
  }
}