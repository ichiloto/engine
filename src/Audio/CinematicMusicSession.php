<?php

namespace Ichiloto\Engine\Audio;

/** Non-blocking lifecycle for one cinematic music interruption. */
final class CinematicMusicSession
{
  protected float $remaining;
  protected string $phase;
  protected(set) bool $isReady = false;
  protected(set) bool $isFinalized = false;

  public function __construct(
    protected AudioManager $audio,
    public readonly CinematicMusicRequest $request,
    public readonly BackgroundMusicState $previous,
  )
  {
    $this->phase = $previous->track !== null && $request->fadeOut > 0.0 ? 'intro_fade_out' : 'start';
    $this->remaining = $this->phase === 'intro_fade_out' ? $request->fadeOut : 0.0;

    if ($this->remaining <= 0.0) {
      $this->update(0.0);
    }
  }

  /** Advances the command-start transition. */
  public function update(float $deltaSeconds): bool
  {
    if ($this->isReady || $this->isFinalized) {
      return true;
    }

    $this->remaining -= max(0.0, $deltaSeconds);

    while (! $this->isReady && $this->remaining <= 0.0) {
      $overflow = abs(min(0.0, $this->remaining));
      $this->advancePhase();
      $this->remaining -= $overflow;
    }

    return $this->isReady;
  }

  /** Starts authored completion, skip, or failure cleanup. */
  public function finalize(string $outcome = 'complete'): void
  {
    if ($this->isFinalized || str_starts_with($this->phase, 'final_')) {
      return;
    }

    if ($this->request->completionBehavior === 'continue') {
      $this->isFinalized = true;
      $this->phase = 'finalized';
      return;
    }

    $this->phase = 'final_fade_out';
    $this->remaining = $this->request->fadeOut;

    if ($this->remaining <= 0.0) {
      $this->advanceFinalPhase();
    }
  }

  /** Advanced by AudioManager after the event session has ended. */
  public function updateFinalization(float $deltaSeconds): bool
  {
    if ($this->isFinalized) {
      return true;
    }

    if (! str_starts_with($this->phase, 'final_')) {
      return false;
    }

    $this->remaining = max(0.0, $this->remaining - max(0.0, $deltaSeconds));

    if ($this->remaining <= 0.0) {
      $this->advanceFinalPhase();
    }

    return $this->isFinalized;
  }

  protected function advancePhase(): void
  {
    if ($this->phase === 'intro_fade_out' || $this->phase === 'start') {
      $this->audio->playBackgroundMusic($this->request->track, $this->request->loop);
      $this->phase = 'intro_fade_in';
      $this->remaining = $this->request->fadeIn;
      return;
    }

    if ($this->phase === 'intro_fade_in' && $this->remaining <= 0.0) {
      $this->phase = 'playing';
      $this->isReady = true;
    }
  }

  protected function advanceFinalPhase(): void
  {
    if ($this->request->completionBehavior === 'restore_previous' && $this->previous->track !== null) {
      $this->audio->playBackgroundMusic($this->previous->track, $this->previous->loop);
    } else {
      $this->audio->stopBackgroundMusic();
    }

    $this->phase = 'finalized';
    $this->isFinalized = true;
  }
}
