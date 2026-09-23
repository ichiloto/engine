<?php

namespace Ichiloto\Engine\Animations;

use Ichiloto\Engine\Core\Timers;
use InvalidArgumentException;

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
   * @param callable|null $onCue Receives every cue independently of rendering.
   *   Required for reduced motion when cues precede the final frame.
   * @return void
   */
  public function play(Animation $animation, callable $renderFrame, ?callable $onCue = null, bool $reducedMotion = false): void
  {
    if ($reducedMotion) {
      if ($onCue === null) {
        for ($frameIndex = 1; $frameIndex < $animation->maxFrames; $frameIndex++) {
          if ($animation->getCue($frameIndex) !== null) {
            throw new InvalidArgumentException('Reduced-motion animation playback requires a cue callback for cues before the final frame.');
          }
        }
      }
      for ($frameIndex = 1; $frameIndex <= $animation->maxFrames; $frameIndex++) {
        $cue = $animation->getCue($frameIndex);
        if ($cue !== null && $onCue !== null) { $onCue($cue, $frameIndex); }
      }
      $last = $animation->maxFrames;
      $renderFrame($last, $animation->getFrame($last), $onCue === null ? $animation->getCue($last) : null);
      return;
    }
    $session = new AnimationPlaybackSession($animation, $this->secondsPerFrame);

    while (! $session->isComplete) {
      $frameIndex = $session->currentFrame;
      $cue = $animation->getCue($frameIndex);
      if ($cue !== null && $onCue !== null) { $onCue($cue, $frameIndex); }
      try {
        $renderFrame(
          $frameIndex,
          $animation->getFrame($frameIndex),
          $onCue === null ? $cue : null,
        );
      } catch (\Exception $error) {
        if ($onCue !== null) {
          for ($next = $frameIndex + 1; $next <= $animation->maxFrames; $next++) {
            if (($nextCue = $animation->getCue($next)) !== null) { $onCue($nextCue, $next); }
          }
        }
        throw $error;
      }

      Timers::wait($this->secondsPerFrame);
      $session->update($this->secondsPerFrame);
    }
  }
}
