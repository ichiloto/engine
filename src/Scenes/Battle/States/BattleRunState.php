<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;

/**
 * Represents the battle run state.
 *
 * @package Ichiloto\Engine\Scenes\Battle\States
 */
class BattleRunState extends BattleSceneState
{

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    // TODO: Implement execute() method.
    $this->handleActions();
  }

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->scene->ui->render();
  }

  /**
   * @return void
   * @throws \Exception
   */
  protected function handleActions(): void
  {
    if (Input::isButtonDown("quit")) {
      $this->scene->getGame()->quit();
    }

    if (Input::isButtonDown("pause")) {
      $this->setState($this->scene->pauseState);
    }
  }
}