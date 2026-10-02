<?php

namespace Ichiloto\Engine\Rendering;

use Closure;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** PHP-owned traversal, with a safe scene handoff independent of renderer acknowledgements. */
final class ScreenTransitionSession
{
  protected float $elapsed = 0.0;
  protected int $frameIndex = 0;
  protected ?array $currentFrame = null;
  protected(set) bool $isComplete = false;
  public private(set) ScreenTransitionPhase $phase = ScreenTransitionPhase::GATHER;
  public private(set) bool $hasHandedOff = false;
  private ?Closure $present = null;
  private ?Closure $handoff = null;
  private ?Closure $ready = null;
  private ?Closure $cleanup = null;
  private bool $paused = false;
  private ?ScreenTransitionPhase $paintedPhase = null;
  private float $paintedProgress = 0;
  private int $phaseElapsedNanoseconds = 0;

  /** @param array<int, array{0: string, 1: int}> $frames */
  public function __construct(
    protected ScreenTransition|ScreenTransitionTreatment $transition,
    protected array $frames = [],
    ?callable $present = null,
    ?callable $handoff = null,
    ?callable $ready = null,
    ?callable $cleanup = null,
    private bool $enabled = true,
  )
  {
    if ($transition instanceof ScreenTransitionTreatment) {
      if ($present === null || $handoff === null || $ready === null || $cleanup === null || $frames !== []) {
        throw new InvalidArgumentException('A graphical transition needs one presentation and owned handoff/readiness/cleanup callbacks.');
      }
      $this->present = Closure::fromCallable($present);
      $this->handoff = Closure::fromCallable($handoff);
      $this->ready = Closure::fromCallable($ready);
      $this->cleanup = Closure::fromCallable($cleanup);
      return;
    }
    if (! $transition->isEnabled() || $frames === []) {
      $this->isComplete = true;
    }
  }

  public function update(float $deltaSeconds): bool
  {
    if (!is_finite($deltaSeconds)) { throw new InvalidArgumentException('Transition elapsed time must be finite.'); }
    if ($this->isComplete) {
      return true;
    }
    if ($this->paused) { return false; }
    if ($this->transition instanceof ScreenTransitionTreatment) {
      try { return $this->advanceHandoff(max(0, $deltaSeconds)); }
      catch (Throwable $error) {
        try { $this->cancel(); } catch (Throwable) { /* Preserve the original failure. */ }
        throw $error;
      }
    }

    $this->elapsed += max(0.0, $deltaSeconds);
    $frameSeconds = max(0.001, ($this->transition->durationMs / 1000) / count($this->frames));

    while ($this->frameIndex < count($this->frames)
      && $this->elapsed + PHP_FLOAT_EPSILON >= $frameSeconds
    ) {
      $this->elapsed -= $frameSeconds;
      [$fill, $columns] = $this->frames[$this->frameIndex++];
      $this->currentFrame = [$fill, $columns];
      $this->transition->renderFrame($fill, $columns);
    }

    $this->isComplete = $this->frameIndex >= count($this->frames);
    return $this->isComplete;
  }

  public function cancel(): void
  {
    $this->finish(ScreenTransitionPhase::CANCELLED);
  }

  public function pause(): void
  {
    $this->paused = true;
  }

  public function resume(): void
  {
    $this->paused = false;
  }

  public function hasRenderedFrame(): bool
  {
    return $this->currentFrame !== null || $this->paintedPhase !== null;
  }

  /** Repaints the most recent frame after a field composition redraw. */
  public function renderCurrentFrame(): void
  {
    if ($this->isComplete && $this->transition instanceof ScreenTransitionTreatment) { return; }
    if ($this->paintedPhase !== null) {
      ($this->present)($this->paintedPhase, $this->paintedProgress);
      return;
    }
    if ($this->currentFrame === null) {
      return;
    }

    [$fill, $columns] = $this->currentFrame;
    $this->transition->renderFrame($fill, $columns);
  }

  private function advanceHandoff(float $delta): bool
  {
    $treatment = $this->transition;
    if (!$treatment instanceof ScreenTransitionTreatment) { throw new LogicException('Handoff requires a graphical treatment.'); }
    if (!$this->enabled) {
      $this->performHandoff();
      if (($this->ready)()) { $this->finish(ScreenTransitionPhase::COMPLETE); }
      return $this->isComplete;
    }
    // Match the monotonic clock's resolution, avoiding float drift at phase boundaries.
    $limit = (int) round(array_sum($treatment->timings) * 1_000_000_000);
    $tick = (int) round(min($delta, array_sum($treatment->timings)) * 1_000_000_000);
    $this->phaseElapsedNanoseconds = min($limit, $this->phaseElapsedNanoseconds + $tick);
    if ($this->phase === ScreenTransitionPhase::GATHER) {
      $duration = $this->getPhaseDurationNanoseconds($treatment);
      if ($this->phaseElapsedNanoseconds < $duration) {
        $this->presentPhase($this->phase, $this->phaseElapsedNanoseconds / $duration);
        return false;
      }
      $this->phaseElapsedNanoseconds -= $duration;
      $this->phase = ScreenTransitionPhase::COVER;
    }
    if ($this->phase === ScreenTransitionPhase::COVER) {
      $duration = $this->getPhaseDurationNanoseconds($treatment);
      if ($this->phaseElapsedNanoseconds < $duration) {
        $this->presentPhase($this->phase, $this->phaseElapsedNanoseconds / $duration);
        return false;
      }
      // A skipped frame must still emit full cover before the next update may hand off.
      $this->phase = ScreenTransitionPhase::HOLD;
      $this->phaseElapsedNanoseconds = 0;
      $this->presentPhase($this->phase, 1);
      return false;
    }
    if ($this->phase === ScreenTransitionPhase::HOLD) {
      $this->performHandoff();
      $ready = ($this->ready)();
      $this->presentPhase($this->phase, 1);
      if ($ready && $this->phaseElapsedNanoseconds >= $this->getPhaseDurationNanoseconds($treatment)) {
        $this->phase = ScreenTransitionPhase::REVEAL;
        $this->phaseElapsedNanoseconds = 0;
      }
      // The incoming replacement also starts covered, even if readiness is immediate.
      return false;
    }
    $duration = $this->getPhaseDurationNanoseconds($treatment);
    if ($this->phaseElapsedNanoseconds >= $duration) {
      $this->finish(ScreenTransitionPhase::COMPLETE);
      return true;
    }
    $this->presentPhase(ScreenTransitionPhase::REVEAL, $this->phaseElapsedNanoseconds / $duration);
    return false;
  }

  private function getPhaseDurationNanoseconds(ScreenTransitionTreatment $treatment): int
  {
    return (int) round($treatment->timings[$this->phase->value] * 1_000_000_000);
  }

  private function performHandoff(): void
  {
    if ($this->hasHandedOff) { return; }
    $this->hasHandedOff = true;
    ($this->handoff)();
  }

  private function presentPhase(ScreenTransitionPhase $phase, float $progress): void
  {
    $this->paintedPhase = $phase;
    $this->paintedProgress = $progress;
    ($this->present)($phase, $progress);
  }

  private function finish(ScreenTransitionPhase $phase): void
  {
    if ($this->isComplete) { return; }
    $this->isComplete = true;
    $this->phase = $phase;
    $this->paintedPhase = null;
    $cleanup = $this->cleanup;
    $this->cleanup = null;
    $cleanup?->__invoke();
  }
}
