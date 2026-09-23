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
    ?callable $onFrame = null,
    bool $reducedMotion = false,
  ): void
  {
    $session = new SummonPlaybackSession($cutscene, loop: false);

    if ($reducedMotion) {
      for ($frame = 0; $frame < $session->totalFrames; $frame++) {
        if ($onFrame !== null) { $onFrame($frame); }
        if ($onCue !== null) {
          foreach ($session->cuesAt($frame) as $cue) { $onCue($cue, $frame); }
        }
      }
      $session->seek($session->totalFrames - 1);
      $renderFrame($session->currentFrame, $session->activeSegments());
      return;
    }

    $pendingCues = $session->takeCurrentFrameCues();

    while (! $session->isCompleted) {
      $frame = $session->currentFrame;
      if ($onFrame !== null) { $onFrame($frame); }
      if ($onCue !== null) {
        foreach ($pendingCues as $cue) { $onCue($cue, intval($cue['frame'] ?? $frame)); }
      }
      try {
        $renderFrame($frame, $session->activeSegments());
      } catch (\Exception $error) {
        for ($next = $frame + 1; $next < $session->totalFrames; $next++) {
          if ($onFrame !== null) { $onFrame($next); }
          if ($onCue !== null) {
            foreach ($session->cuesAt($next) as $cue) { $onCue($cue, $next); }
          }
        }
        throw $error;
      }

      Timers::wait($session->secondsPerFrame);
      $pendingCues = $session->update($session->secondsPerFrame)->crossedCues;
    }
  }
}
