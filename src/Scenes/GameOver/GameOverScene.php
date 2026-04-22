<?php

namespace Ichiloto\Engine\Scenes\GameOver;

use Exception;
use Ichiloto\Engine\Core\Menu\Commands\ContinueGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\QuitGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\ToTitleMenuCommand;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\GameOver\Menus\GameOverMenu;
use Ichiloto\Engine\Util\Debug;
use Throwable;

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
   * @var ContinueGameCommand|null The continue command entry in the game-over menu.
   */
  protected ?ContinueGameCommand $continueCommand = null;
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
    $this->headerContent = $this->loadHeaderContent();
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
    $this->continueCommand = new ContinueGameCommand($this->menu, $gameLoader);

    $this
      ->menu
      ->addItem($this->continueCommand)
      ->addItem(new ToTitleMenuCommand($this->menu))
      ->addItem(new QuitGameCommand($this->menu));
    $this->syncContinueAvailability();

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

    $x = intval((get_screen_width() - $headerWidth) / 2);
    $y = 2;

    $this->camera->draw($this->headerContent, $x, $y);
  }

  /**
   * Loads the game-over header art, falling back to a built-in banner when
   * the project has not provided a `Graphics/System/game-over.txt` asset yet.
   *
   * @return string
   */
  protected function loadHeaderContent(): string
  {
    try {
      return graphics('System/game-over', false);
    } catch (Throwable $exception) {
      Debug::warn($exception->getMessage());

      return implode(PHP_EOL, [
        '================',
        '   GAME OVER',
        '================',
      ]);
    }
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    Console::clear();
    $this->syncContinueAvailability();
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

  /**
   * Re-centers the game-over layout after a terminal resize.
   *
   * @param int $width The new terminal width.
   * @param int $height The new terminal height.
   * @return void
   */
  public function onScreenResize(int $width, int $height): void
  {
    parent::onScreenResize($width, $height);

    if (! isset($this->menu)) {
      return;
    }

    $this->menu->setPosition(new Vector2(max(0, intdiv(get_screen_width() - 16, 2)), $this->headerHeight + 2));
    $this->syncContinueAvailability();

    Console::clear();
    $this->renderHeader();
    $this->menu->render();
  }

  /**
   * Synchronizes the Continue command's availability with the save directory.
   *
   * @return void
   */
  protected function syncContinueAvailability(): void
  {
    if (! $this->continueCommand instanceof ContinueGameCommand) {
      return;
    }

    if ($this->sceneManager->saveManager->hasSaveFiles(true)) {
      $this->continueCommand->enable();
    } else {
      $this->continueCommand->disable();
    }

    $this->menu?->updateWindowContent();
  }
}
