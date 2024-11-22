<?php

namespace Ichiloto\Engine\Core\Menu\TitleMenu;

use Ichiloto\Engine\Core\Menu\Commands\MenuCommandExecutionContext;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuItemInterface;
use Ichiloto\Engine\Core\Menu\Menu;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use Ichiloto\Engine\UI\Windows\Enumerations\VerticalAlignment;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\UI\Windows\WindowAlignment;
use Ichiloto\Engine\UI\Windows\WindowPadding;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * The TitleMenu class. Represents the title menu.
 *
 * @package Ichiloto\Engine\Core\Menu\TitleMenu
 */
class TitleMenu extends Menu
{
  /**
   * @var Window|null $window The window of the menu.
   */
  protected ?Window $window = null;
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

    if (Input::isAnyKeyPressed([KeyCode::ENTER])) {
      $selectedCommand = $this->items->toArray()[$this->activeIndex];
      $selectedCommand->execute($this->executionContext);
    }
  }

  /**
   * Updates the window content.
   */
  protected function updateWindowContent(): void
  {
    $content = [];
    /**
     * @var int $itemIndex
     * @var MenuItemInterface $item
     */
    foreach ($this->items as $itemIndex => $item) {
      $color = $item->isDisabled() ? Color::BLUE->value : '';
      $prefix = '  ';

      if ($itemIndex === $this->activeIndex) {
        $prefix = "$this->cursor ";
      }

      $output = $prefix . $item->getLabel();
      $content[] = $output;
    }

    $this->window->setContent($content);
  }
}