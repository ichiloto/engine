<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use DateTimeImmutable;
use InvalidArgumentException;

/** PHP-owned decorative time. Drawing reads state; it never advances the clock. */
final class TitlePlayback
{
  public private(set) float $elapsed = 0;
  public private(set) float $nightWeight = 0;
  public private(set) bool $reducedMotion = false;
  public private(set) bool $obscured = false;
  private ?float $lastTime = null;
  private bool $initialized = false;
  private float $nextClockCheck = 0;
  private float $target = 0;
  private float $fadeStart = 0;
  private float $fadeFrom = 0;

  public float $entryOpacity { get => $this->reducedMotion ? 1 : min(1, $this->elapsed / 0.28); }

  public function advance(float $now, ?DateTimeImmutable $localTime, bool $reducedMotion): void
  {
    if (!is_finite($now) || $now < 0 || ($this->lastTime !== null && $now < $this->lastTime)) {
      throw new InvalidArgumentException('Title time must be finite, nonnegative and monotonic.');
    }
    $first = !$this->initialized;
    $delta = $this->lastTime === null ? 0 : min(0.1, $now - $this->lastTime);
    $this->lastTime = $now;
    $this->reducedMotion = $reducedMotion;
    if ($this->obscured) { return; }
    $this->initialized = true;
    $this->elapsed += $delta;
    if ($first || $now >= $this->nextClockCheck) {
      $localTime ??= new DateTimeImmutable();
      $hour = (int)$localTime->format('G');
      $target = $hour >= 6 && $hour < 18 ? 0.0 : 1.0;
      if ($first) { $this->nightWeight = $this->target = $target; }
      elseif ($target !== $this->target) {
        $this->fadeFrom = $this->nightWeight;
        $this->fadeStart = $this->elapsed;
        $this->target = $target;
      }
      $boundary = $localTime->setTime($hour < 6 ? 6 : 18, 0);
      if ($hour >= 18) { $boundary = $localTime->modify('+1 day')->setTime(6, 0); }
      $this->nextClockCheck = $now + min(1, max(0.001,
        (float)$boundary->format('U.u') - (float)$localTime->format('U.u')));
    }
    $ratio = min(1, max(0, ($this->elapsed - $this->fadeStart) / ($reducedMotion ? 0.16 : 1)));
    $ratio = $ratio * $ratio * (3 - 2 * $ratio);
    if (!$first) { $this->nightWeight = $this->fadeFrom + ($this->target - $this->fadeFrom) * $ratio; }
    else { $this->fadeFrom = $this->target; }
  }

  public function setObscured(bool $obscured, float $now): void
  {
    if (!is_finite($now) || $now < 0 || ($this->lastTime !== null && $now < $this->lastTime)) {
      throw new InvalidArgumentException('Title visibility time must be monotonic.');
    }
    if ($this->obscured === $obscured) { return; }
    $this->obscured = $obscured;
    // No catch-up after a modal, destination, focus loss or scene suspension.
    $this->lastTime = $now;
    $this->nextClockCheck = 0;
  }
}
