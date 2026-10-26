<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;

/** Keeps a temporary overlay visible for an authored duration. */
final class TimedPresentationOperation implements EventPendingOperationInterface
{
  protected float $remaining;
  private readonly ?CinematicTextPresentation $overlay;

  public function __construct(
    protected CinematicPresentationManager $presentation,
    float $seconds,
  )
  {
    $this->remaining = max(0.0, $seconds);
    $this->overlay = $presentation->overlayPresentation;
    $this->overlay?->setDuration($this->remaining);
  }

  public function update(float $deltaSeconds): bool
  {
    $this->remaining = max(0.0, $this->remaining - max(0.0, $deltaSeconds));
    $this->overlay?->advance($deltaSeconds);

    if ($this->remaining <= 0.0) {
      if ($this->overlay !== null) { $this->presentation->clearOverlay($this->overlay); }
      return true;
    }

    return false;
  }

  public function cancel(): void
  {
    $this->remaining = 0.0;
    if ($this->overlay !== null) { $this->presentation->clearOverlay($this->overlay); }
  }
}
