<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Audio;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use WeakMap;

/**
 * The counter tick: one looping system sound shared by every on-screen count.
 *
 * Any presentation whose displayed number moves over time (battle results
 * EXP, a points countdown) holds the tick on every frame its count is moving
 * and releases it when the count finishes, is skipped, or its screen closes.
 * However many counts run together, exactly one loop plays; it stops as soon
 * as the last holder releases it.
 *
 * A hold lasts until the next audio update. A holder that stops renewing it
 * (its screen was torn down, or a blocking modal froze its clock) lapses
 * there, so a missed release can never leave the tick playing.
 *
 * Counts that do not animate (the terminal projection, reduced motion) never
 * hold the tick: they show the final value at once and play nothing.
 *
 * @package Ichiloto\Engine\Audio
 */
final class CounterSound
{
  /**
   * The counts holding the tick, each with whether it renewed its hold since
   * the last audio update. Weak, so a discarded count holds nothing.
   *
   * @var WeakMap<object, bool>
   */
  private WeakMap $holders;

  /**
   * The loop sounding the tick while any count holds it.
   *
   * @var SoundEffectLoop|null
   */
  private ?SoundEffectLoop $loop = null;

  /**
   * Whether any count currently holds the tick.
   *
   * @var bool
   */
  public bool $isHeld {
    get => count($this->holders) > 0;
  }

  /**
   * Whether the tick's loop is running (it may still be silent while sound
   * effects are off).
   *
   * @var bool
   */
  public bool $isPlaying {
    get => $this->loop !== null && ! $this->loop->isStopped;
  }

  /**
   * @param AudioManager $audio The manager that plays the tick.
   */
  public function __construct(private readonly AudioManager $audio)
  {
    $this->holders = new WeakMap();
  }

  /**
   * Holds the tick for this frame on behalf of a moving count. Starts the
   * loop when it is the first holder; further holders share that loop.
   *
   * @param object $counter The count's owner, used only as its identity.
   * @return void
   */
  public function holdTick(object $counter): void
  {
    $this->holders[$counter] = true;

    if (! $this->isPlaying) {
      $this->loop = $this->audio->startSystemSoundLoop(SystemSound::COUNTER);
    }
  }

  /**
   * Releases a count's hold: its count finished, was skipped or closed. The
   * loop stops when no other count still holds it.
   *
   * @param object $counter The count's owner.
   * @return void
   */
  public function releaseTick(object $counter): void
  {
    unset($this->holders[$counter]);

    if (! $this->isHeld) {
      $this->stopTick();
    }
  }

  /**
   * Lapses every hold that was not renewed since the previous update, then
   * stops the loop if nothing holds it. Pumped once per frame by the
   * AudioManager, after the scenes have updated their counts.
   *
   * @return void
   */
  public function update(): void
  {
    // Read every hold first: the map itself changes below.
    $holds = [];
    foreach ($this->holders as $counter => $isRenewed) {
      $holds[] = [$counter, $isRenewed];
    }

    foreach ($holds as [$counter, $isRenewed]) {
      if ($isRenewed) {
        $this->holders[$counter] = false;
      } else {
        unset($this->holders[$counter]);
      }
    }

    if (! $this->isHeld) {
      $this->stopTick();
    }
  }

  /**
   * Stops the tick and drops every hold, whoever holds it. Used when audio
   * shuts down.
   *
   * @return void
   */
  public function stopTick(): void
  {
    $this->holders = new WeakMap();
    $this->loop?->stop();
    $this->loop = null;
  }
}
