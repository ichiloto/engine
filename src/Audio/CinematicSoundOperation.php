<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Audio;

use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;
use InvalidArgumentException;

/** An authored lane interval with ownership of exactly one Effects playback. */
final class CinematicSoundOperation implements EventPendingOperationInterface
{
  public function __construct(private float $remainingSeconds, private ?AudioPlayback $playback)
  {
  }

  public function update(float $deltaSeconds): bool
  {
    if (!is_finite($deltaSeconds) || $deltaSeconds < 0) {
      throw new InvalidArgumentException('Sound elapsed time must be finite and nonnegative.');
    }

    $this->remainingSeconds = max(0.0, $this->remainingSeconds - $deltaSeconds);
    if ($this->remainingSeconds > 0.0) { return false; }
    $this->cancel();

    return true;
  }

  public function cancel(): void
  {
    $playback = $this->playback;
    $this->playback = null;
    $this->remainingSeconds = 0.0;
    $playback?->stop();
  }
}
