<?php

namespace Ichiloto\Engine\Scenes\Title;

use Ichiloto\Engine\Core\Menu\Commands\LoadSceneCommand;
use Ichiloto\Engine\Core\Menu\Commands\QuitGameCommand;
use Ichiloto\Engine\Core\Menu\TitleMenu\TitleMenu;
use Ichiloto\Engine\Core\Rect;
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
   * The header.
   *
   * @var array
   */
  protected array $headerLines = [];
  /**
   * The header height.
   *
   * @var int
   */
  protected int $headerHeight = 0;

  /**
   * @inheritDoc
   */
  #[Override]
  public function start(): void
  {
    $menuWidth = 20;
    $menuHeight = 3;

    parent::start();
    $this->headerContent = graphics('System/title');
    $this->headerLines = explode("\n", $this->headerContent);
    $this->headerHeight = count($this->headerLines);

    $leftMargin = intval((DEFAULT_SCREEN_WIDTH - $menuWidth) / 2);
    $topMargin = $this->headerHeight + 2;

    $this->menu = new TitleMenu(
      $this,
      '',
      '',
      rect: new Rect($leftMargin, $topMargin, $menuWidth, $menuHeight)
    );
    $this
      ->menu
      ->addItem(new LoadSceneCommand('New Game', 'Start a new game.', '', 'game'))
      ->addItem(new LoadSceneCommand('Continue', 'Start a new game.', '', 'continue'))
      ->addItem(new QuitGameCommand());
    $this->renderHeader();
    usleep(300);
    $this->menu->render();
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function update(): void
  {
    parent::update();
    $this->menu->update();
  }

  /**
   * Renders the header.
   *
   * @return void
   */
  public function renderHeader(): void
  {
    $headerWidth = 0;

    foreach ($this->headerLines as $line) {
      $headerWidth = max($headerWidth, mb_strlen($line));
    }

    $x = intval((DEFAULT_SCREEN_WIDTH - $headerWidth) / 2);
    $y = 2;

    Console::write($this->headerContent, $x, $y);
  }
}