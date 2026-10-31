<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Audio;

use Ichiloto\Engine\Audio\Enumerations\CountKind;
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
 * Each hold names its kind of count ({@see CountKind}), and each kind loops
 * its own system sound, so a game can give dispensed EXP its own tally. A
 * kind whose sound the game leaves unbound loops the generic counter: that
 * fallback lives here, never at a call site.
 *
 * When counts of different kinds overlap, the sounding count keeps the loop:
 * the kind already playing goes on while any count of that kind still holds
 * it. Once the last count of that kind lets go, the loop changes to the kind
 * of the earliest remaining holder. Kinds that resolve to the same sound
 * share one uninterrupted loop.
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
   * The counts holding the tick, in the order they began holding it, each
   * with its kind and whether it renewed its hold since the last audio
   * update. Weak, so a discarded count holds nothing.
   *
   * @var WeakMap<object, array{kind: CountKind, isRenewed: bool}>
   */
  private WeakMap $holders;

  /**
   * The loop sounding the tick while any count holds it.
   *
   * @var SoundEffectLoop|null
   */
  private ?SoundEffectLoop $loop = null;

  /**
   * The kind of count the loop sounds for, while it sounds.
   *
   * @var CountKind|null
   */
  private ?CountKind $loopKind = null;

  /**
   * The system sound the loop plays, while it sounds.
   *
   * @var SystemSound|null
   */
  private ?SystemSound $loopSound = null;

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
   * @param CountKind $kind What the count is counting, which selects its loop.
   * @return void
   */
  public function holdTick(object $counter, CountKind $kind = CountKind::GENERAL): void
  {
    $this->holders[$counter] = ['kind' => $kind, 'isRenewed' => true];
    $this->syncLoop();
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
    $this->syncLoop();
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
    foreach ($this->holders as $counter => $hold) {
      $holds[] = [$counter, $hold];
    }

    foreach ($holds as [$counter, $hold]) {
      if ($hold['isRenewed']) {
        $this->holders[$counter] = ['kind' => $hold['kind'], 'isRenewed' => false];
      } else {
        unset($this->holders[$counter]);
      }
    }

    $this->syncLoop();
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
    $this->stopLoop();
  }

  /**
   * Makes the loop match the holds: none without a holder, else the loop of
   * the kind that sounds ({@see selectSoundingKind()}).
   *
   * @return void
   */
  private function syncLoop(): void
  {
    $kind = $this->selectSoundingKind();

    if ($kind === null) {
      $this->stopLoop();
      return;
    }

    if ($this->isPlaying && $kind === $this->loopKind) {
      return;
    }

    $sound = $this->resolveSound($kind);
    $this->loopKind = $kind;

    if ($this->isPlaying && $sound === $this->loopSound) {
      return;
    }

    $this->loop?->stop();
    $this->loop = $this->audio->startSystemSoundLoop($sound);
    $this->loopSound = $sound;
  }

  /**
   * The kind the loop sounds for: the one already sounding while a count of
   * that kind still holds it, else the earliest remaining holder's.
   *
   * @return CountKind|null The kind, or null when nothing holds the tick.
   */
  private function selectSoundingKind(): ?CountKind
  {
    $earliest = null;

    foreach ($this->holders as $hold) {
      if ($hold['kind'] === $this->loopKind) {
        return $hold['kind'];
      }

      $earliest ??= $hold['kind'];
    }

    return $earliest;
  }

  /**
   * The system sound a kind of count loops: its own when the game binds a
   * track to it, else the generic counter.
   *
   * @param CountKind $kind The kind of count.
   * @return SystemSound The sound to loop.
   */
  private function resolveSound(CountKind $kind): SystemSound
  {
    $sound = $kind->getSystemSound();

    return $this->audio->hasSystemSoundTrack($sound) ? $sound : SystemSound::COUNTER;
  }

  /**
   * Stops the loop, whichever kind it sounds for.
   *
   * @return void
   */
  private function stopLoop(): void
  {
    $this->loop?->stop();
    $this->loop = null;
    $this->loopKind = null;
    $this->loopSound = null;
  }
}
