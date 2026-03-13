<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use RuntimeException;

class BattleDefeatState extends BattleSceneState
{
  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->ui->hideControls();
    $this->scene->resultWindow?->display($this->scene->result ?? throw new RuntimeException('Battle result is not set.'));
  }

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    if (Input::isButtonDown('action')) {
      $this->scene->shouldLoadGameOver = true;
      $this->setState($this->scene->endState);
    }
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->scene->resultWindow?->erase();
  }
}
