<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * NewGameCommand is a command that starts a new game.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
class NewGameCommand extends MenuItem
{
  /**
   * NewGameCommand constructor.
   *
   * @param MenuInterface $menu The menu.
   * @param GameLoader $gameLoader The game loader.
   */
  public function __construct(
    MenuInterface $menu,
    protected GameLoader $gameLoader
  )
  {
    $label = config(ProjectConfig::class, 'vocab.command.new_game') ?? 'New Game';
    parent::__construct($menu, $label, 'Start a new game.', '');
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
    $currentScene->configure($this->gameLoader->loadNewGame());

    return self::SUCCESS;
  }
}