<?php

namespace Ichiloto\Engine\Audio;

use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;

/** Adapts cinematic music startup to an EventInterpreter lane. */
final class CinematicMusicOperation implements EventPendingOperationInterface
{
  public function __construct(protected CinematicMusicSession $session)
  {
  }

  public function update(float $deltaSeconds): bool
  {
    return $this->session->update($deltaSeconds);
  }

  public function cancel(): void
  {
    // The owning cinematic controller applies the authored completion policy.
  }
}
