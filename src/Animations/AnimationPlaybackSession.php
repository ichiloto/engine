<?php

namespace Ichiloto\Engine\Animations;

use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession;
use InvalidArgumentException;

/** One-based compatibility API; the shared effect playhead owns all traversal. */
final class AnimationPlaybackSession
{
  public readonly EffectPlaybackSession $playback;
  private bool $cancelled = false;
  public int $currentFrame { get => $this->playback->currentFrame + 1; }
  public bool $isComplete { get => $this->cancelled || $this->playback->isCompleted; }

  public function __construct(
    public readonly Animation $animation,
    public readonly float $secondsPerFrame = 0.12,
  )
  {
    if (!is_finite($secondsPerFrame)) {
      throw new InvalidArgumentException('Legacy animation frame duration must be finite.');
    }
    // Legacy frame hosts own their flash tail and cues, not the traversal clock.
    $this->playback = new EffectPlaybackSession(new CompiledEffectTimeline('legacy-frames-' . $animation->id, '',
      fps: 1, defaults: ['lengthFrames' => $animation->maxFrames]), false,
      secondsPerFrame: max(0.01, $secondsPerFrame));
  }

  /** @return int[] Frames entered by this elapsed-time update. */
  public function update(float $deltaSeconds): array
  {
    if ($this->isComplete) {
      return [];
    }

    return array_map(static fn(int $frame): int => $frame + 1, $this->playback->update($deltaSeconds)->crossedFrames);
  }

  public function cancel(): void
  {
    $this->cancelled = true;
    $this->playback->pause();
  }
}
