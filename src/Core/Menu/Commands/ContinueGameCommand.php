<?php

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\IO\Saves\SaveFeedback;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\GameOver\GameOverScene;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Util\Debug;
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
    $label = Vocabulary::getTerm('game.continue', Vocabulary::getTerm('command.continue', 'Continue'));
    parent::__construct($menu, $label, 'Continue from a save file.', '');

    $scene = $menu->getScene();
    $saves = $scene->getGame()->getSaveManager();
    $available = $scene instanceof TitleScene ? $saves->hasEnabledSaveFiles(true)
      : $saves->getLatestLoadableSaveFile(true) !== null;
    if (!$available) {
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

    $sceneManager = $context->sceneManager;
    try {
      $config = $this->gameLoader->loadSavedGame($savedGameFilePath);
      $sceneManager->loadSavedGameFrom($context->scene, $config);
    } catch (Throwable $error) {
      if ($sceneManager->currentScene !== $context->scene) { throw $error; }
      Debug::warn('Could not continue from the latest save: ' . $error->getMessage());
      $context->game->getModalManager()->queueAlert(SaveFeedback::describeLoadFailure($error), $this->getLabel());
      return self::FAILURE;
    }

    if ($context->scene instanceof GameOverScene) {
      $this->enable();
    }

    return self::SUCCESS;
  }
}
