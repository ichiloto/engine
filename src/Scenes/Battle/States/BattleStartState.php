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
  protected bool $isPlayingIntroAnimation = true;
  protected float $introAnimationStartTime = 0;
  /**
   * @var string[] The frames of the intro animation.
   */
  protected array $frames = [];
  /**
   * @var int The total number of frames.
   */
  protected int $totalFrames = 0;
  /**
   * @var int The index of the current frame.
   */
  protected int $currentFrameIndex = 0;
  /**
   * @var float The duration of the animation.
   */
  protected float $animationDuration = 0.2; // seconds
  /**
   * @var int The time to sleep between frames.
   */
  protected int $sleepTime = 1000000;
  /**
   * @var string[] The clean slate to clear the screen.
   */
  protected array $cleanSlate = [];

  #[Override]
  public function enter(): void
  {
    $this->scene->ui = new BattleScreen($this->scene);
    $this->cleanSlate = array_fill(0, $this->scene->ui->screenDimensions->getHeight(), str_repeat(' ', $this->scene->ui->screenDimensions->getWidth()));
    Console::clear();
    $this->startTheIntroAnimation();
  }

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    $this->playIntroAnimation();

    if (! $this->isPlayingIntroAnimation) {
      $this->setState($this->scene->runState);
    }
  }

  public function exit(): void
  {
    $this->introAnimationStartTime = Time::getTime();
    Console::clear();
  }

  /**
   * Start playing the intro animation.
   *
   * @return void
   */
  protected function startTheIntroAnimation(): void
  {
    Debug::info('Playing intro animation...');
    $this->isPlayingIntroAnimation = true;

    $this->loadAnimationFrameData();
    $this->playIntroAnimation();
  }

  /**
   * @return void
   */
  protected function loadAnimationFrameData(): void
  {
    $frameSeparator = "@@---\n";
    $animationData = graphics('Animations/battle-transition', false);
    $this->frames = explode($frameSeparator, $animationData);
    $this->totalFrames = count($this->frames);
    $this->currentFrameIndex = 0;
    $this->sleepTime = intval( (1000000 * $this->animationDuration) / $this->totalFrames );
  }

  protected function renderFrame(string $frame, int $x = 0, int $y = 0): void
  {
    $this->scene->camera->draw($frame, $x, $y);
  }

  protected function clearScreen(int $x = 0, int $y = 0): void
  {
    Console::clear();
  }

  /**
   * Play the intro animation.
   *
   * @return void
   */
  protected function playIntroAnimation(): void
  {
    $this->clearScreen(
      $this->scene->ui->screenDimensions->getLeft(),
      $this->scene->ui->screenDimensions->getTop()
    );
    $this->renderFrame(
      $this->frames[$this->currentFrameIndex],
      $this->scene->ui->screenDimensions->getLeft(),
      $this->scene->ui->screenDimensions->getTop()
    );
    $this->currentFrameIndex++;

    if ($this->currentFrameIndex >= $this->totalFrames) {
      $this->isPlayingIntroAnimation = false;
    }

    usleep($this->sleepTime);
  }
}