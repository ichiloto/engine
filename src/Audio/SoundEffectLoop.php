<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Audio;

/**
 * An owned handle to a looping sound effect.
 *
 * The AudioManager owns the player process behind the handle: it spawns it,
 * keeps it in step with the sound effect toggle and the master volume, and
 * respawns it on players that cannot loop by themselves. The holder only
 * stops the loop, which ends the process at once. A stopped handle stays
 * stopped; starting the sound again takes a new handle.
 *
 * @package Ichiloto\Engine\Audio
 */
final class SoundEffectLoop
{
  /**
   * Whether the loop has been stopped and released by its manager.
   *
   * @var bool
   */
  public bool $isStopped {
    get => ! $this->audio->hasSoundEffectLoop($this);
  }

  /**
   * Whether a player process is sounding the loop right now. False while
   * sound effects are off or the volume is zero, even though the loop is
   * still held and resumes when they come back.
   *
   * @var bool
   */
  public bool $isAudible {
    get => $this->audio->isSoundEffectLoopAudible($this);
  }

  /**
   * @param AudioManager $audio The manager that owns the player process.
   * @param string $path The resolved path of the looping track.
   * @param float $volume The loop's own volume between 0.0 and 1.0, scaled
   *   by the master volume.
   */
  public function __construct(
    private readonly AudioManager $audio,
    public readonly string $path,
    public readonly float $volume,
  )
  {
  }

  /**
   * Stops the loop and ends its player process. Safe to call more than once.
   *
   * @return void
   */
  public function stop(): void
  {
    $this->audio->stopSoundEffectLoop($this);
  }
}
