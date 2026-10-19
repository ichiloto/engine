<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

use InvalidArgumentException;

/** Per-iteration sleep budget, independent of simulation time and renderer choice. */
final readonly class FramePacing
{
    private float $frameBudgetMicroseconds;

    public function __construct(mixed $framesPerSecond)
    {
        if (!is_int($framesPerSecond) && !is_float($framesPerSecond)
            && !(is_string($framesPerSecond) && is_numeric($framesPerSecond))) {
            throw new InvalidArgumentException('FPS must be a finite positive number.');
        }

        $framesPerSecond = (float) $framesPerSecond;
        if (!is_finite($framesPerSecond) || $framesPerSecond <= 0) {
            throw new InvalidArgumentException('FPS must be a finite positive number.');
        }

        $budget = 1_000_000 / $framesPerSecond;
        if (!is_finite($budget) || $budget >= PHP_INT_MAX) {
            throw new InvalidArgumentException('FPS must yield a representable microsecond frame budget.');
        }
        $this->frameBudgetMicroseconds = $budget;
    }

    /** Both timestamps come from the same monotonic nanosecond clock (hrtime(true)). */
    public function getRemainingSleepMicroseconds(int|float $frameStartedNanoseconds, int|float $nowNanoseconds): int
    {
        $elapsedMicroseconds = max(0, $nowNanoseconds - $frameStartedNanoseconds) / 1_000;
        return (int) max(0, $this->frameBudgetMicroseconds - $elapsedMicroseconds);
    }
}
