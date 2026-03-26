<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\GameOver\GameOverScene;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Throwable;

/**
 * Opens or executes the most relevant continue flow for the current scene.
 *
 * @package Ichiloto\Engine\Core\Menu\Commands
 */
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
    parent::__construct($menu, $label, 'Continue from a save file.', '');

    if (! SaveManager::getInstance($menu->getScene()->getGame())->hasSaveFiles(true)) {
      $this->disable();
    }
  }

  /**
   * @inheritDoc
   * @throws NotFoundException
   */
  public function execute(?ExecutionContextInterface $context = null): int
  {
    if ($this->isDisabled()) {
      return self::FAILURE;
    }

    if (! $context instanceof MenuCommandExecutionContext) {
      throw new NotFoundException('The context is not a menu command execution context.');
    }

    if ($context->scene instanceof TitleScene) {
      $context->scene->openContinueMenu();
      return self::SUCCESS;
    }

    $savedGameFilePath = $context->sceneManager->saveManager->getLatestLoadableSaveFile(true);

    if ($savedGameFilePath === null) {
      $this->disable();
      return self::FAILURE;
    }

    $currentScene = $context->sceneManager->loadScene(GameScene::class)->currentScene;

    if (! $currentScene instanceof GameScene) {
      throw new NotFoundException('The current scene is not a game scene.');
    }

    try {
      $currentScene->configure($this->gameLoader->loadSavedGame($savedGameFilePath));
    } catch (Throwable) {
      return self::FAILURE;
    }

    if ($context->scene instanceof GameOverScene) {
      $this->enable();
    }

    return self::SUCCESS;
  }
}
