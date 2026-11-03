<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Clocks\MonotonicClockInterface;
use Ichiloto\Engine\Core\Clocks\RuntimeClock;
use Ichiloto\Engine\Core\Clocks\SystemMonotonicClock;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\ManualRuntimeClock;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

require_once __DIR__ . '/../Support/ManualRuntimeClock.php';

beforeEach(function () {
    $this->clockStatics = [];
    foreach ([RuntimeClock::class, Time::class, Timers::class, InputManager::class] as $class) {
        $this->clockStatics[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    Timers::clear();
    Timers::setFrameTick(null);
    InputManager::setInputSource(new FakeInputSource());
    new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
});

afterEach(function () {
    foreach ($this->clockStatics as $class => $values) {
        foreach ($values as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
    }
});

it('bridges arbitrary source origins and restores a continuous native timeline with one owned lease', function () {
    $before = RuntimeClock::getSeconds();
    $source = new ManualRuntimeClock();
    $lease = RuntimeClock::useClock($source);
    try {
        $start = RuntimeClock::getSeconds();
        expect($start)->toBeGreaterThanOrEqual($before);
        $source->seconds += 900;
        expect(RuntimeClock::getSeconds() - $start)->toEqualWithDelta(900, 0.000001);
        expect(fn() => RuntimeClock::useClock(new ManualRuntimeClock()))->toThrow(LogicException::class);
        $end = RuntimeClock::getSeconds();
    } finally { $lease->close(); }
    expect(RuntimeClock::getSeconds())->toBeGreaterThanOrEqual($end);
    $lease->close();
    $next = RuntimeClock::useClock(new ManualRuntimeClock());
    $next->close();
});

it('drives elapsed time input idle and owned timers from the same explicit source without wall sleeping', function () {
    $source = new ManualRuntimeClock();
    $lease = RuntimeClock::useClock($source);
    try {
        Time::setElapsedTime(12);
        expect(Time::getDeltaTime())->toBe(0.0);
        InputManager::setInputSource(new FakeInputSource(KeyCode::ENTER));
        InputManager::handleInput();
        $source->seconds += 0.75;
        Time::onGameUpdate();
        InputManager::handleInput();
        expect(Time::getTime())->toEqualWithDelta(12.75, 0.000001)
            ->and(Time::getDeltaTime())->toEqualWithDelta(0.75, 0.000001)
            ->and(InputManager::getSecondsSinceInput())->toEqualWithDelta(0.75, 0.000001);
        $runs = 0; $fractions = [];
        Timers::after(0.5, function () use (&$runs) { $runs++; });
        Timers::setFrameTick(function () { Time::onGameUpdate(); Timers::update(); });
        Timers::wait(0.8, function (float $fraction) use (&$fractions) { $fractions[] = $fraction; });
        expect($runs)->toBe(1)->and($source->sleeps)->toBeGreaterThan(0)
            ->and(InputManager::getSecondsSinceInput())->toEqualWithDelta(1.55, 0.00001)
            ->and(end($fractions))->toBe(1.0);
        InputManager::setInputSource(new FakeInputSource(KeyCode::ENTER));
        InputManager::handleInput();
        expect(InputManager::getSecondsSinceInput())->toBe(0.0);
    } finally { Timers::setFrameTick(null); $lease->close(); }
});

it('qualifies a replacement before changing the source and restores the native source after a bad clock', function () {
    $source = new ManualRuntimeClock(); $source->seconds = NAN;
    expect(fn() => RuntimeClock::useClock($source))->toThrow(RuntimeException::class, 'finite');
    $source->seconds = 10;
    $lease = RuntimeClock::useClock($source);
    $source->seconds = 11;
    $last = RuntimeClock::getSeconds();
    $source->seconds = 9;
    expect(fn() => RuntimeClock::getSeconds())->toThrow(RuntimeException::class, 'nondecreasing');
    expect(fn() => $lease->close())->toThrow(RuntimeException::class);
    expect(RuntimeClock::getSeconds())->toBeGreaterThanOrEqual($last);
    $next = RuntimeClock::useClock(new ManualRuntimeClock()); $next->close();
});

it('refuses invalid durations and a sleep source that cannot advance', function () {
    $source = new ManualRuntimeClock();
    $lease = RuntimeClock::useClock($source);
    try {
        foreach ([-1.0, INF, NAN] as $seconds) {
            expect(fn() => RuntimeClock::sleep($seconds))->toThrow(InvalidArgumentException::class);
            expect(fn() => new SystemMonotonicClock()->sleep($seconds))->toThrow(InvalidArgumentException::class);
        }
        expect(fn() => new SystemMonotonicClock()->sleep(PHP_INT_MAX / 1_000_000))
            ->toThrow(InvalidArgumentException::class);
        RuntimeClock::sleep(0);
        expect($source->sleeps)->toBe(0);
        $source->advances = false;
        expect(fn() => Timers::wait(0.1))->toThrow(RuntimeException::class, 'advance');
    } finally { $lease->close(); }
});

it('releases an abandoned lease and keeps system timestamp queries physical', function () {
    $source = new ManualRuntimeClock();
    $lease = RuntimeClock::useClock($source);
    $source->seconds += 1800;
    Time::setElapsedTime(0);
    expect(Time::getSystemTime())->toBeGreaterThan(1_000_000_000);
    unset($lease);
    $next = RuntimeClock::useClock(new ManualRuntimeClock()); $next->close();
});


it('keeps very short finite waits from spinning on a truncated microsecond remainder', function () {
    $source = new ManualRuntimeClock();
    $lease = RuntimeClock::useClock($source);
    try {
        Timers::wait(0.0000003);
        expect($source->sleeps)->toBeGreaterThan(0)->and($source->seconds)->toBeLessThan(0.000001);
        foreach ([INF, NAN] as $seconds) {
            expect(fn() => Timers::wait($seconds))->toThrow(InvalidArgumentException::class);
        }
    } finally { $lease->close(); }
});

it('rebases saved elapsed time without retaining a stale frame delta or changing input idle age', function () {
    $source = new ManualRuntimeClock();
    $lease = RuntimeClock::useClock($source);
    try {
        Time::setElapsedTime(0);
        InputManager::handleInput();
        $source->seconds += 2;
        Time::onGameUpdate();
        expect(Time::getDeltaTime())->toEqualWithDelta(2, 0.000001);
        Time::setElapsedTime(900);
        expect(Time::getDeltaTime())->toBe(0.0)->and(Time::getTime())->toBe(900.0)
            ->and(InputManager::getSecondsSinceInput())->toEqualWithDelta(2, 0.000001);
        $source->seconds += 0.5;
        Time::onGameUpdate();
        expect(Time::getDeltaTime())->toEqualWithDelta(0.5, 0.000001)
            ->and(Time::getTime())->toEqualWithDelta(900.5, 0.000001);
    } finally { $lease->close(); }
});
