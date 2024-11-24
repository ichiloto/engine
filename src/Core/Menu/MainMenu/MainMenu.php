<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu;

use Ichiloto\Engine\Core\Menu\Menu;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * MainMenu is the main menu of the game.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu
 */
class MainMenu extends Menu
{
  /**
   * The width of the main menu.
   */
  protected const int WIDTH = 30;
  /**
   * The height of the main menu.
   */
  protected const int HEIGHT = 22;

  /**
   * @inheritDoc
   */
  public function activate(): void
  {
    $position = new Vector2($this->rect->getX(), $this->rect->getY());
    $this->window = new Window(
      '',
      '',
      $position,
      $this->rect->getWidth() ?? self::WIDTH,
      $this->rect->getHeight() ?? self::HEIGHT,
      $this->borderPack
    );
  }

  /**
   * @inheritDoc
   */
  public function deactivate(): void
  {
    Console::clear();
  }

  /**
   * @inheritDoc
   */
  public function render(?int $x = null, ?int $y = null): void
  {
    $this->window->render($x, $y);
  }

  /**
   * @inheritDoc
   */
  public function erase(?int $x = null, ?int $y = null): void
  {
    $this->window->erase($x, $y);
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    // Do nothing
  }
}