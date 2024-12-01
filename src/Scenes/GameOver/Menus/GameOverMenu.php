<?php

namespace Ichiloto\Engine\Scenes\GameOver\Menus;

use Ichiloto\Engine\Core\Menu\Commands\MenuCommandExecutionContext;
use Ichiloto\Engine\Core\Menu\Menu;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use Ichiloto\Engine\UI\Windows\Enumerations\VerticalAlignment;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\UI\Windows\WindowAlignment;
use Ichiloto\Engine\UI\Windows\WindowPadding;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * The game over menu.
 *
 * @package Ichiloto\Engine\Scenes\GameOver\Menus
 */
class GameOverMenu extends Menu
{
  /**
   * @var WindowAlignment $alignment The alignment of the menu.
   */
  protected WindowAlignment $alignment;
  /**
   * @var WindowPadding $padding The padding of the menu.
   */
  protected WindowPadding $padding;
  /**
   * @var MenuCommandExecutionContext $executionContext The menu command execution context.
   */
  protected MenuCommandExecutionContext $executionContext;

  /**
   * @inheritDoc
   */
  public function activate(): void
  {
    $this->alignment = new WindowAlignment(HorizontalAlignment::LEFT, VerticalAlignment::MIDDLE);
    $this->padding = new WindowPadding(1, 1, 1, 1);

    $this->window = new Window(
      '',
      '',
      new Vector2($this->rect->getX(), $this->rect->getY()),
      $this->rect->getWidth(),
      $this->rect->getHeight(),
      new DefaultBorderPack(),
      $this->alignment,
      $this->padding,
    );

    $this->executionContext = new MenuCommandExecutionContext(
      [],
      new ConsoleOutput(),
      $this,
      $this->getScene(),
    );
    $this->updateWindowContent();
  }

  /**
   * @inheritDoc
   */
  public function deactivate(): void
  {
    // Do nothing
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
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      $this->activeIndex += $v;
      $this->activeIndex = wrap($this->activeIndex, 0, $this->totalItems - 1);
      $this->updateWindowContent();
      $this->render();
    }

    if (Input::isButtonDown("confirm")) {
      $selectedCommand = $this->items->toArray()[$this->activeIndex];
      $selectedCommand->execute($this->executionContext);
    }
  }
}