<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

use Ichiloto\Engine\Core\Timers;

/**
 * Plays compiled summon cutscenes frame-by-frame.
 *
 * @package Ichiloto\Engine\Cutscenes\Summons
 */
final class SummonCutscenePlayer
{
  /**
   * @param callable(int, array<int, array<string, mixed>>): void $renderFrame
   * @param callable(array<string, mixed>, int): void|null $onCue
   * @return void
   */
  public function play(
    SummonCompiledCutscene $cutscene,
    callable $renderFrame,
    ?callable $onCue = null,
  ): void
  {
    $session = new SummonPlaybackSession($cutscene, loop: false);

    while (! $session->isCompleted) {
      $frame = $session->currentFrame;
      $renderFrame($frame, $session->activeSegments());

      if ($onCue !== null) {
        foreach ($session->cuesAt() as $cue) {
          $onCue($cue, $frame);
        }
      }

      Timers::wait($session->secondsPerFrame);
      $session->update($session->secondsPerFrame);
    }
  }
}
