<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\SceneStateContext;

class BattleEndState extends BattleSceneState
{
  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->scene->resultWindow?->erase();
    $this->scene->ui?->erase();
    $this->engine->stop();
    Console::clear();

    if ($this->scene->shouldLoadGameOver) {
      $this->scene->getGame()->sceneManager->loadGameOverScene();
      return;
    }

    $this->scene->getGame()->sceneManager->returnFromBattleScene();
  }

  public function execute(?SceneStateContext $context = null): void
  {
    // Do nothing. Transition happens when the state is entered.
  }
}
