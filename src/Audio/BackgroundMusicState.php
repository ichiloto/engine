<?php

namespace Ichiloto\Engine\Audio;

/** Restorable identity and loop policy for background music. */
final readonly class BackgroundMusicState
{
  public function __construct(
    public ?string $track,
    public bool $loop = true,
  )
  {
  }
}
