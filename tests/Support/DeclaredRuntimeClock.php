<?php

declare(strict_types=1);

namespace Tests\Support;

use Ichiloto\Engine\Core\Clocks\MonotonicClockInterface;
use Ichiloto\Engine\Core\Clocks\RuntimeClock;
use Ichiloto\Engine\Core\Clocks\RuntimeClockLease;
use LogicException;
use ReflectionClass;

/**
 * Leases a test's declared clock so the Engine reads the declared steps exactly.
 *
 * RuntimeClock bridges a new source onto its previous reading, so deadlines never jump backwards. In a test that
 * reading is the machine's monotonic uptime, whose binary fraction is arbitrary: uptime plus a declared step is
 * then rounded whenever the sum crosses a power of two, and an exact boundary moves with the machine (an 8 second
 * state read at 7.99999999999997 seconds when uptime is near 195 seconds). This lease bridges as RuntimeClock
 * does, then moves the bridged origin forward to the next whole second. Earlier readings and deadlines keep a
 * nondecreasing timeline, and every declared step that is a multiple of a quarter second stays exact.
 */
final class DeclaredRuntimeClock
{
    /** Quarter-second steps stay exact below 2^51 seconds; this bound keeps a margin above any real uptime. */
    private const float LARGEST_EXACT_ORIGIN = 2 ** 50;

    public static function lease(MonotonicClockInterface $source): RuntimeClockLease
    {
        $lease = RuntimeClock::useClock($source);
        $clock = new ReflectionClass(RuntimeClock::class);
        $origin = ceil($clock->getStaticPropertyValue('origin'));
        if ($origin > self::LARGEST_EXACT_ORIGIN) {
            $lease->close();
            throw new LogicException('The runtime timeline is too far along for exact declared seconds.');
        }
        $clock->setStaticPropertyValue('origin', $origin);
        $clock->setStaticPropertyValue('lastSeconds', $origin);

        return $lease;
    }
}
