<?php

namespace Ichiloto\Engine\Animations;

use Ichiloto\Engine\Core\Timers;

/**
 * Plays a stored animation frame-by-frame.
 *
 * @package Ichiloto\Engine\Animations
 */
final class AnimationPlayer
{
  /**
   * @param float $secondsPerFrame The playback duration for each frame.
   */
  public function __construct(
    public float $secondsPerFrame = 0.12,
  )
  {
    $this->secondsPerFrame = max(0.01, $secondsPerFrame);
  }

  /**
   * Plays the animation and calls the provided renderer for each frame.
   *
   * @param Animation $animation The animation to play.
   * @param callable $renderFrame Receives the frame index, frame, and optional cue.
   * @return void
   */
  public function play(Animation $animation, callable $renderFrame): void
  {
    $session = new AnimationPlaybackSession($animation, $this->secondsPerFrame);

    while (! $session->isComplete) {
      $frameIndex = $session->currentFrame;
      $renderFrame(
        $frameIndex,
        $animation->getFrame($frameIndex),
        $animation->getCue($frameIndex),
      );

      Timers::wait($this->secondsPerFrame);
      $session->update($this->secondsPerFrame);
    }
  }
}
