<?php

namespace Ichiloto\Engine\Animations;

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
    for ($frameIndex = 1; $frameIndex <= $animation->maxFrames; $frameIndex++) {
      $renderFrame(
        $frameIndex,
        $animation->getFrame($frameIndex),
        $animation->getCue($frameIndex),
      );

      usleep(intval(round($this->secondsPerFrame * 1000000)));
    }
  }
}
