<?php

namespace Ichiloto\Engine\Scenes\Title;

use Ichiloto\Engine\Core\Menu\TitleMenu\TitleMenu;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Util\Debug;
use Override;

/**
 * The title scene.
 */
class TitleScene extends AbstractScene
{
  /**
   * The title menu.
   *
   * @var TitleMenu
   */
  protected TitleMenu $menu;

  /**
   * The header content.
   *
   * @var string
   */
  protected string $headerContent = '';

  /**
   * @inheritDoc
   */
  #[Override]
  public function start(): void
  {
    $this->menu = new TitleMenu('', '');
    parent::start();
    $this->headerContent = graphics('System/title');

    $this->renderHeader();
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function update(): void
  {
    parent::update();
    $this->menu->update();
    Debug::log('TitleScene::update()');
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function render(): void
  {
    parent::render();
    $this->menu->render();
  }

  /**
   * Renders the header.
   *
   * @return void
   */
  public function renderHeader(): void
  {
    Debug::log('TitleScene::renderHeader()');
    $header = explode("\n", $this->headerContent);
    $headerWidth = 0;
    $headerHeight = count($header);

    foreach ($header as $line) {
      $headerWidth = max($headerWidth, strlen($line));
    }

    $x = ($this->headerContent - $headerWidth) / 2;
    $y = 0;

    for ($i = 0; $i < $headerHeight; $i++) {
      Console::write($header[$i], $x, $y + $i);
    }
  }
}