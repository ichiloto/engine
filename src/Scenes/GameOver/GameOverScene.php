<?php

namespace Ichiloto\Engine\Scenes\GameOver;

use Exception;
use Ichiloto\Engine\Core\Menu\Commands\ContinueGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\QuitGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\ToTitleMenuCommand;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\GameOver\Menus\GameOverMenu;

/**
 * The game over scene.
 *
 * @package Ichiloto\Engine\Scenes\GameOver
 */
class GameOverScene extends AbstractScene
{
  /**
   * @var GameOverMenu The game over menu.
   */
  protected GameOverMenu $menu;
  /**
   * @var string The header content.
   */
  protected string $headerContent = '';
  /**
   * @var array The header.
   */
  protected array $headerLines = [];
  /**
   * @var int The header height.
   */
  protected int $headerHeight = 0;

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    $gameLoader = GameLoader::getInstance($this->getGame());
    $menuWidth = 16;
    $menuHeight = 3;

    parent::start();
    $this->headerContent = graphics('System/game-over');
    $this->headerLines = explode("\n", $this->headerContent);
    $this->headerHeight = count($this->headerLines);

    $leftMargin = intval((get_screen_width() - $menuWidth) / 2);
    $topMargin = $this->headerHeight + 2;

    $this->menu = new GameOverMenu(
      $this,
      '',
      '',
      rect: new Rect($leftMargin, $topMargin, $menuWidth, $menuHeight)
    );
    $this
      ->menu
      ->addItem(new ContinueGameCommand($this->menu, $gameLoader))
      ->addItem(new ToTitleMenuCommand($this->menu))
      ->addItem(new QuitGameCommand($this->menu));

    Console::clear();
    $this->renderHeader();
    usleep(300);
    $this->menu->render();
  }

  /**
   * @inheritDoc
   * @throws Exception
   */
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

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    Console::clear();
    usleep(300);
    $this->renderHeader();
    usleep(300);
    $this->menu->render();
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    Console::clear();
  }
}