<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

use Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession;

/** Compatibility API for summons, using the shared effect playhead. */
final class SummonPlaybackSession extends EffectPlaybackSession
{
  public function __construct(public readonly SummonCompiledCutscene $cutscene, ?bool $loop = null, ?float $speed = null)
  {
    parent::__construct($cutscene, $loop, $speed);
  }

  public function activeSegments(?int $frame = null): array { return $this->getActiveSegments($frame); }
  public function cuesAt(?int $frame = null): array { return $this->getCuesAt($frame); }

  public function update(float $elapsedSeconds): SummonPlaybackUpdate
  {
    $update = parent::update($elapsedSeconds);
    return new SummonPlaybackUpdate($update->crossedFrames, $update->crossedCues);
  }
}
