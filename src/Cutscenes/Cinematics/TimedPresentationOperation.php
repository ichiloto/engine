<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;

/** Keeps a temporary overlay visible for an authored duration. */
final class TimedPresentationOperation implements EventPendingOperationInterface
{
  protected float $remaining;

  public function __construct(
    protected CinematicPresentationManager $presentation,
    float $seconds,
  )
  {
    $this->remaining = max(0.0, $seconds);
  }

  public function update(float $deltaSeconds): bool
  {
    $this->remaining = max(0.0, $this->remaining - max(0.0, $deltaSeconds));

    if ($this->remaining <= 0.0) {
      $this->presentation->clear();
      return true;
    }

    return false;
  }

  public function cancel(): void
  {
    $this->remaining = 0.0;
    $this->presentation->clear();
  }
}
