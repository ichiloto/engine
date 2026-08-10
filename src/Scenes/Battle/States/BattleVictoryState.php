<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use RuntimeException;

class BattleVictoryState extends BattleSceneState
{
  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $this->playVictoryMusic();
    $this->ui->hideControls();
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
  }
}
