<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\Clocks;

/** One runtime time source. Calendar dates and hardware/transport deadlines use physical time separately. */
interface MonotonicClockInterface
{
    /** Finite, nondecreasing seconds from any stable origin. */
    public function getSeconds(): float;

    /** Wait on this source; a positive duration must advance it. */
    public function sleep(float $seconds): void;
}
