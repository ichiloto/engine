<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;
use Ichiloto\Engine\Rendering\ScreenTransitionSession;

/** Adapts the existing ScreenTransition session to an event lane. */
final class TransitionOperation implements EventPendingOperationInterface
{
  protected bool $finalStateApplied = false;

  public function __construct(
    protected ScreenTransitionSession $session,
    protected CinematicPresentationManager $presentation,
    protected string $direction,
  )
  {
    $this->direction = strtolower(trim($direction)) === 'in' ? 'in' : 'out';
    $this->presentation->presentTransition($session);

    if ($session->isComplete) {
      $this->applyFinalState();
    }
  }

  public function update(float $deltaSeconds): bool
  {
    $complete = $this->session->update($deltaSeconds);

    if ($complete) {
      $this->applyFinalState();
    }

    return $complete;
  }

  public function cancel(): void
  {
    $this->session->cancel();
    $this->presentation->clearTransition();
  }

  protected function applyFinalState(): void
  {
    if ($this->finalStateApplied) {
      return;
    }

    $this->finalStateApplied = true;
    $this->presentation->finishTransition($this->direction);
  }
}
