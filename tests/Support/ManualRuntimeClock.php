<?php

declare(strict_types=1);

namespace Tests\Support;

use Ichiloto\Engine\Core\Clocks\MonotonicClockInterface;

/** Explicit declared time for runtime behavior tests; never launches or paces a native consumer. */
final class ManualRuntimeClock implements MonotonicClockInterface
{
    public float $seconds = 0;
    public int $sleeps = 0;
    public bool $advances = true;
    public function getSeconds(): float { return $this->seconds; }
    public function sleep(float $seconds): void { $this->sleeps++; if ($this->advances) { $this->seconds += $seconds; } }
}
