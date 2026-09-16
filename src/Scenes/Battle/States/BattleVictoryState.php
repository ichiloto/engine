<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\Scenes\SceneStateContext;
use RuntimeException;

class BattleVictoryState extends BattleSceneState
{
  private bool $resetClockBoundary = true;

  public function resume(): void
  {
    $this->resetClockBoundary = true;
  }
  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->resetClockBoundary = true;
    $this->playVictoryMusic();
    $this->ui->hideControls();
    $this->scene->beginResults();
    if ($this->scene->resultsPlayback !== null) {
      if (!$this->scene->hasGraphicalResults()) { $this->scene->resultWindow?->displayPlayback($this->scene->resultsPlayback); }
      return;
    }
    $this->scene->resultWindow?->display($this->scene->result ?? throw new RuntimeException('Battle result is not set.'));
  }

  /**
   * Starts the victory theme, replacing the battle music.
   *
   * The track plays for as long as the results are on screen; returning to
   * the field then restores the map's own theme through the scene-music
   * choke point. A project that configures no victory theme simply keeps the
   * battle music playing, so this stays optional like the rest of the audio.
   *
   * @return void
   */
  protected function playVictoryMusic(): void
  {
    $track = $this->scene->getVictoryMusic();

    if ($track === null) {
      return;
    }

    play_music($track);
  }

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    if (($playback = $this->scene->resultsPlayback) !== null) {
      $playback->update($this->resetClockBoundary ? 0 : max(0, Time::getDeltaTime()));
      $this->resetClockBoundary = false;
      $direction = Input::getAxis(AxisName::VERTICAL);
      if ($direction !== 0.0) { $playback->navigate($direction > 0 ? 1 : -1); }
      $confirmed = Input::isButtonDown('action') && $playback->confirm();
      if ($confirmed || $playback->isFinished()) {
        $this->scene->shouldLoadGameOver = false;
        $this->setState($this->scene->endState);
        return;
      }
      if (!$this->scene->hasGraphicalResults()) { $this->scene->resultWindow?->displayPlayback($playback); }
      return;
    }
    $revealedThisFrame = $this->scene->resultWindow?->update() ?? false;

    if (Input::isButtonDown('action')) {
      if ($this->scene->resultWindow && ! $this->scene->resultWindow->isComplete()) {
        if (! $revealedThisFrame) {
          $this->scene->resultWindow->advance();
        }
        return;
      }

      $this->scene->shouldLoadGameOver = false;
      $this->setState($this->scene->endState);
    }
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    $this->scene->resultWindow?->erase();
    $this->scene->endResults();
  }
}
