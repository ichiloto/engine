<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use InvalidArgumentException;

/** PHP-owned rolling time; composition only reads the offset. */
final class CreditsPlayback
{
  public private(set) float $offset = 0;
  private ?float $lastTime = null;
  private bool $paused = false;

  public bool $finished { get => $this->offset >= $this->distance; }

  public function __construct(public readonly float $distance, private readonly float $pixelsPerSecond)
  {
    if (!is_finite($distance) || $distance <= 0 || !is_finite($pixelsPerSecond) || $pixelsPerSecond <= 0) {
      throw new InvalidArgumentException('Credits require a finite positive distance and speed.');
    }
  }

  public function advance(float $now, bool $paused = false): void
  {
    if (!is_finite($now) || $now < 0 || ($this->lastTime !== null && $now < $this->lastTime)) {
      throw new InvalidArgumentException('Credits time must be finite, nonnegative and monotonic.');
    }
    $delta = $this->lastTime === null || $paused || $this->paused ? 0 : min(0.1, $now - $this->lastTime);
    $this->lastTime = $now;
    $this->paused = $paused;
    $this->offset = min($this->distance, $this->offset + $delta * $this->pixelsPerSecond);
  }
}
