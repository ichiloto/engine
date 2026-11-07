<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\Menu\Commands;

use Ichiloto\Engine\Core\Interfaces\ExecutionContextInterface;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Menu\MenuItem;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\UI\Modal\ModalManager;

/** An explicit exit from the guide, available through ordinary menu navigation. */
final class SkipTutorialCommand extends MenuItem
{
  public function __construct(MenuInterface $menu)
  {
    parent::__construct($menu, 'Skip Tutorial', 'End the current guide and return to the field.');
  }

  public function execute(?ExecutionContextInterface $context = null): int
  {
    $state = $context instanceof MenuCommandExecutionContext ? ($context->args['state'] ?? null) : null;
    if (!$context instanceof MenuCommandExecutionContext || !$context->scene instanceof GameScene || !$state instanceof MainMenuState) {
      return self::FAILURE;
    }

    $scene = $context->scene;
    $director = $scene->tutorialDirector;
    $tutorial = $director?->getSkippableTutorial($scene->party, $scene->questManager?->log);
    if ($tutorial === null || $scene->fieldState === null) {
      return self::FAILURE;
    }

    if (ModalManager::getInstance($scene->getGame())->confirm(
      'Skip this tutorial?',
      $this->getLabel(),
      confirmLabel: 'Skip',
      cancelLabel: 'Continue',
    ) && $scene->state === $state && $scene->tutorialDirector === $director
      && $director->skipTutorial($tutorial->id, $scene->party, $scene->questManager?->log)) {
      $state->startingIndex = 0;
      $state->setState($scene->fieldState);
    }

    return self::SUCCESS;
  }
}
