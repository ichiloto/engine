<?php

namespace Ichiloto\Engine\Cutscenes\Summons;

use Closure;
use Ichiloto\Engine\Animations\Timelines\EffectImpactTimingMode;

/** Resolves one battle action at its authored cue, frame, or timeline end. */
final class SummonEffectTrigger
{
  private bool $applied = false;
  private Closure $apply;
  /** @var array<string, mixed> */
  private readonly array $timing;

  public function __construct(array $timing, callable $apply)
  {
    $this->timing = SummonEffectTiming::fromArray($timing)->toArray();
    $this->apply = Closure::fromCallable($apply);
  }

  public function onFrame(int $frame): void
  {
    $mode = $this->timing['mode'] ?? SummonEffectTiming::DEFAULT_MODE;
    if (($mode === 'explicit_frame' || EffectImpactTimingMode::tryFrom($mode)?->usesFrame())
      && $frame >= intval($this->timing['frame'] ?? PHP_INT_MAX)) {
      $this->applyOnce();
    }
  }

  public function onCue(array $cue): void
  {
    if (($this->timing['mode'] ?? SummonEffectTiming::DEFAULT_MODE) === 'cue'
      && strval($cue['id'] ?? '') === strval($this->timing['cueId'] ?? '')) {
      $this->applyOnce();
    }
  }

  /** End timing and early presentation failures both preserve gameplay. */
  public function finish(): void
  {
    $this->applyOnce();
  }

  private function applyOnce(): void
  {
    if ($this->applied) { return; }
    $this->applied = true;
    ($this->apply)();
  }
}
