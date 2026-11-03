<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\Clocks;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Shared seconds for gameplay, owned waits and presentation. A host can drive the normal runtime with an
 * explicit source. Source origins are bridged on entry/release, so existing deadlines never jump backwards.
 * One host owns an override at a time; a lease cannot replace another active host's clock.
 */
final class RuntimeClock
{
    private static ?MonotonicClockInterface $source = null;
    private static float $sourceOrigin = 0;
    private static float $origin = 0;
    private static ?float $lastSeconds = null;
    private static ?int $owner = null;
    private static int $nextOwner = 0;

    public static function getSeconds(): float
    {
        $raw = self::readSource(self::getSource());
        $seconds = self::$origin + ($raw - self::$sourceOrigin);
        if (!is_finite($seconds) || (self::$lastSeconds !== null && $seconds < self::$lastSeconds)) {
            throw new RuntimeException('The runtime clock must provide finite nondecreasing seconds.');
        }
        return self::$lastSeconds = $seconds;
    }

    public static function sleep(float $seconds): void
    {
        if (!is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('Clock sleep requires finite nonnegative seconds.');
        }
        if ($seconds === 0.0) { return; }
        $before = self::getSeconds();
        self::getSource()->sleep($seconds);
        if (self::getSeconds() <= $before) {
            throw new RuntimeException('A positive runtime sleep must advance its clock source.');
        }
    }

    /** Install before session construction; close after its timed resources are released. No setting is persisted. */
    public static function useClock(MonotonicClockInterface $source): RuntimeClockLease
    {
        if (self::$owner !== null) { throw new LogicException('A runtime clock override already has an owner.'); }
        $now = self::getSeconds();
        $raw = self::readSource($source); // Qualify the source before replacing the current one.
        self::selectSource($source, $now, $raw);
        $owner = self::$owner = ++self::$nextOwner;
        return new RuntimeClockLease(static fn() => self::releaseClock($owner));
    }

    private static function releaseClock(int $owner): void
    {
        if (self::$owner !== $owner) { return; }
        $failure = null;
        try { $now = self::getSeconds(); }
        catch (Throwable $error) { $now = self::$lastSeconds ?? self::$origin; $failure = $error; }
        // Restore a sound default even when the custom source failed during cleanup.
        $source = new SystemMonotonicClock();
        self::selectSource($source, $now, self::readSource($source));
        self::$owner = null;
        if ($failure !== null) { throw $failure; }
    }

    private static function getSource(): MonotonicClockInterface
    {
        return self::$source ??= new SystemMonotonicClock();
    }

    private static function readSource(MonotonicClockInterface $source): float
    {
        $seconds = $source->getSeconds();
        if (!is_finite($seconds)) { throw new RuntimeException('Clock seconds must be finite.'); }
        return $seconds;
    }

    private static function selectSource(MonotonicClockInterface $source, float $origin, float $raw): void
    {
        self::$source = $source;
        self::$sourceOrigin = $raw;
        self::$origin = self::$lastSeconds = $origin;
    }
}
