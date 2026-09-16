<?php

namespace Ichiloto\Engine\Battle\Presentation;

use Closure;

/** Presentation seconds only; this policy never advances combat or clears feedback. */
final readonly class BattleFeedbackTiming
{
  /** @param (Closure(): float)|null $clock Optional monotonic seconds source for tests. */
  public function __construct(private ?Closure $clock = null)
  {
  }

  public function now(): float
  {
    return $this->clock !== null ? ($this->clock)() : hrtime(true) / 1_000_000_000;
  }

  public static function duration(float $durationSeconds): float
  {
    return is_finite($durationSeconds) ? max(0.0, $durationSeconds) : 0.0;
  }

  /** Pure bounded progress; a nonpositive duration has no animation interval. */
  public static function progress(float $shownAt, float $durationSeconds, float $now): float
  {
    $durationSeconds = self::duration($durationSeconds);

    if ($durationSeconds === 0.0 || ! is_finite($shownAt) || ! is_finite($now)) {
      return 1.0;
    }

    return max(0.0, min(1.0, ($now - $shownAt) / $durationSeconds));
  }

  /** @return array{rise: float, opacity: float} Visual projection within the existing hold, never an additional timer. */
  public static function motion(float $shownAt, float $durationSeconds, float $now, float $maximumRise, bool $reducedMotion): array
  {
    $progress = self::progress($shownAt, $durationSeconds, $now);
    $fade = max(0.0, min(1.0, ($progress - 0.3) / 0.7));
    $smooth = static fn(float $value): float => $value * $value * (3 - 2 * $value);
    return ['rise' => $reducedMotion ? 0.0 : max(0.0, $maximumRise) * $smooth($progress),
      'opacity' => 1.0 - $smooth($fade)];
  }
}
