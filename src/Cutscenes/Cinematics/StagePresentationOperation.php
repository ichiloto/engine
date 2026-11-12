<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Cutscenes\Presentation\CinematicStageSession;
use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;
use InvalidArgumentException;

/** One lane advances and releases its own stage; Terminal keeps the same authored wait. */
final class StagePresentationOperation implements EventPendingOperationInterface
{
  private float $elapsed = 0.0;
  private bool $complete = false;

  public function __construct(private readonly float $seconds, private readonly CinematicPresentationManager $presentation,
    private readonly ?CinematicStageSession $session = null)
  {
    if (!is_finite($seconds) || $seconds <= 0) { throw new InvalidArgumentException('Stage duration must be finite and positive.'); }
  }

  public function update(float $deltaSeconds): bool
  {
    if ($this->complete) { return true; }
    if (!is_finite($deltaSeconds) || $deltaSeconds < 0) { throw new InvalidArgumentException('Stage elapsed time must be finite and nonnegative.'); }
    if ($this->session !== null && !$this->presentation->hasStagePresentation($this->session)) {
      $this->cancel(); return true;
    }
    $this->elapsed = min($this->seconds, $this->elapsed + $deltaSeconds);
    $this->session?->advanceTo($this->elapsed);
    if ($this->elapsed >= $this->seconds) { $this->cancel(); }
    return $this->complete;
  }

  public function cancel(): void
  {
    if ($this->session !== null) { $this->presentation->releaseStagePresentation($this->session); }
    $this->complete = true;
  }
}
