<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;

/**
 * Runs the troop's due event pages while the battle waits. The running
 * battle state keeps its engine context, so the battle resumes where it
 * paused, or ends with the outcome a page asked for.
 *
 * @package Ichiloto\Engine\Scenes\Battle\States
 */
class BattleEventState extends BattleSceneState
{
  /** @inheritDoc */
  public function enter(): void
  {
    // Page dialogue replaces command input; the battlefield and party status stay in view.
    $this->ui->commandWindow->erase();
    $this->ui->commandContextWindow->erase();
    $this->ui->fieldWindow->clearTargetIndicators();
  }

  /** @inheritDoc */
  public function execute(?SceneStateContext $context = null): void
  {
    if (Input::isButtonDown('quit')) {
      $this->scene->getGame()->quit();
      return;
    }

    if (! ($this->scene->battleEvents?->update(max(0, Time::getDeltaTime())) ?? false)) {
      $this->scene->finishBattleEvents();
    }
  }

  /** @inheritDoc */
  public function exit(): void
  {
    $this->ui->refresh();
  }
}
