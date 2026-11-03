<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\Clocks;

final class SystemMonotonicClock implements MonotonicClockInterface
{
    public function getSeconds(): float { return hrtime(true) / 1_000_000_000; }

    public function sleep(float $seconds): void
    {
        $microseconds = ceil($seconds * 1_000_000);
        if (!is_finite($seconds) || $seconds < 0 || $microseconds >= PHP_INT_MAX) {
            throw new \InvalidArgumentException('Clock sleep requires finite nonnegative representable seconds.');
        }
        if ($seconds > 0) { usleep((int)$microseconds); }
    }
}
