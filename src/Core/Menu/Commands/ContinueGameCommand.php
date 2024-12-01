<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Assegai\Util\Path;
use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

class ContinueGameCommand extends MenuItem
{
  /**
   * ContinueGameCommand constructor.
   *
   * @param MenuInterface $menu The menu.
   * @param GameLoader $gameLoader The game loader.
   */
  public function __construct(
    MenuInterface $menu,
    protected GameLoader $gameLoader
  )
  {
    $label = config(ProjectConfig::class, 'vocab.game.continue') ?? 'Continue';
    parent::__construct($menu, $label, 'Continue the game.', '');
    $this->disabled = true;
  }

  /**
   * @inheritDoc
   * @throws NotFoundException
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    if (! $context instanceof MenuCommandExecutionContext ) {
      throw new NotFoundException('The context is not a menu command execution context.');
    }
    $sceneManager = $context->getSceneManager();
    $currentScene = $sceneManager->loadScene(GameScene::class)->currentScene;

    if (! $currentScene instanceof GameScene ) {
      throw new NotFoundException('The current scene is not a game scene.');
    }

    // TODO: Fetch the saved game file path from the game loader.
    $savedGameFilePath = '';
    foreach ($saveFiles = $sceneManager->saveManager->getSaveFiles(true) as $index => $path ) {
      Debug::log("Path $index: $path");
    }

    $currentScene->configure($this->gameLoader->loadSavedGame($savedGameFilePath));
    $this->disabled = true;

    return self::SUCCESS;
  }
}