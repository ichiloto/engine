<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\Scenes\SceneStateContext;

class BattleEndState extends BattleSceneState
{

  public function execute(?SceneStateContext $context = null): void
  {
    // TODO: Implement execute() method.
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->engine->stop();
  }
}