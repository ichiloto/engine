<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Util\Debug;
use Override;

class BattleStartState extends BattleSceneState
{
  protected bool $playingIntroAnimation = true;
  protected float $introAnimationStartTime = 0;

  #[Override]
  public function enter(): void
  {
    Console::clear();

    $this->startTheIntroAnimation();
  }

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    // TODO: Implement execute() method.
    $animationDuration = 3.0;

    // Determine` this based on whether all the intro animations have finished playing.
    $timeElapsed = Time::getTime() - $this->introAnimationStartTime;
    if($timeElapsed >= $animationDuration) {
      $this->playingIntroAnimation = false;
    }

    if (! $this->playingIntroAnimation) {
      $this->setState($this->scene->runState);
    }
  }

  public function exit(): void
  {
    $this->scene->ui = new BattleScreen($this->scene);
    $this->introAnimationStartTime = Time::getTime();
  }

  /**
   * Start playing the intro animation.
   *
   * @return void
   */
  protected function startTheIntroAnimation(): void
  {
    $this->playingIntroAnimation = true;
    Debug::log('Playing intro animation...');
  }
}