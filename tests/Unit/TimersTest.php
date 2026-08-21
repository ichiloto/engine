<?php

use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Timers;

beforeEach(function () {
  Timers::clear();
  Timers::setFrameTick(null);
});

/**
 * Moves engine time forward, the way a frame would.
 *
 * @param float $seconds How far to jump.
 * @return void
 */
function advanceEngineTime(float $seconds): void
{
  Time::setElapsedTime(Time::getTime() + $seconds);
}

it('runs a one-shot timer once, when it comes due', function () {
  $runs = 0;
  Timers::after(1.0, function () use (&$runs): void {
    $runs++;
  });

  Timers::update();
  expect($runs)->toBe(0)
    ->and(Timers::count())->toBe(1);

  advanceEngineTime(1.0);
  Timers::update();
  Timers::update();

  expect($runs)->toBe(1)
    ->and(Timers::count())->toBe(0);
});

it('reschedules a repeating timer', function () {
  $runs = 0;
  Timers::every(0.5, function () use (&$runs): void {
    $runs++;
  });

  advanceEngineTime(0.5);
  Timers::update();
  advanceEngineTime(0.5);
  Timers::update();

  expect($runs)->toBe(2)
    ->and(Timers::count())->toBe(1);
});

it('cancels a timer before it fires', function () {
  $runs = 0;
  $handle = Timers::after(0.1, function () use (&$runs): void {
    $runs++;
  });

  expect(Timers::cancel($handle))->toBeTrue()
    ->and(Timers::cancel($handle))->toBeFalse();

  advanceEngineTime(1.0);
  Timers::update();

  expect($runs)->toBe(0);
});

it('keeps running the other timers when one throws', function () {
  $ran = false;
  Timers::after(0.1, function (): void {
    throw new RuntimeException('boom');
  });
  Timers::after(0.1, function () use (&$ran): void {
    $ran = true;
  });

  advanceEngineTime(1.0);
  Timers::update();

  // A broken timer is one broken feature, not a dead game.
  expect($ran)->toBeTrue()
    ->and(Timers::count())->toBe(0);
});

it('ticks the world while waiting instead of sleeping through it', function () {
  $ticks = 0;
  Timers::setFrameTick(function () use (&$ticks): void {
    $ticks++;
  });

  Timers::wait(0.05);

  expect($ticks)->toBeGreaterThan(0);
});

it('reports progress to a caller animating through a wait', function () {
  $fractions = [];
  Timers::wait(0.05, function (float $fraction) use (&$fractions): void {
    $fractions[] = $fraction;
  });

  expect($fractions)->not->toBeEmpty()
    ->and($fractions[0])->toBeLessThanOrEqual(1.0)
    ->and(end($fractions))->toBe(1.0);
});

it('returns immediately for a wait of no time at all', function () {
  $ticks = 0;
  Timers::setFrameTick(function () use (&$ticks): void {
    $ticks++;
  });

  Timers::wait(0);

  expect($ticks)->toBe(0);
});
