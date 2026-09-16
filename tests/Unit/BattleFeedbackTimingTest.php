<?php

use Ichiloto\Engine\Battle\Presentation\BattleFeedbackTiming;

it('projects a bounded rise and grouped fade without changing the existing result interval', function () {
  expect(BattleFeedbackTiming::motion(10, 1.2, 10, 32, false))->toBe(['rise' => 0.0, 'opacity' => 1.0]);
  $middle = BattleFeedbackTiming::motion(10, 1.2, 10.6, 32, false);
  expect($middle['rise'])->toEqualWithDelta(16, 0.00001)->and($middle['opacity'])->toBeGreaterThan(0)->toBeLessThan(1);
  expect(BattleFeedbackTiming::motion(10, 1.2, 11.2, 32, false)['opacity'])->toEqualWithDelta(0, 0.00001)
    ->and(BattleFeedbackTiming::motion(10, 1.2, 10.6, 32, true)['rise'])->toBe(0.0)
    ->and(BattleFeedbackTiming::motion(10, 0, 10, 32, false)['opacity'])->toBe(0.0);
});

it('reads presentation seconds from hrtime or an injected clock', function () {
  $before = hrtime(true) / 1_000_000_000;
  $now = new BattleFeedbackTiming()->now();
  $after = hrtime(true) / 1_000_000_000;

  expect($now)->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual($after)
    ->and(new BattleFeedbackTiming(static fn(): float => 123.25)->now())->toBe(123.25);
});

it('bounds feedback progress without a battle tick or mutable animation counter', function ($now, $expected) {
  expect(BattleFeedbackTiming::progress(10.0, 2.0, $now))->toBe($expected);
})->with([[9.0, 0.0], [10.0, 0.0], [10.5, 0.25], [11.0, 0.5], [12.0, 1.0], [100.0, 1.0]]);

it('gives nonpositive or nonfinite durations no animation interval', function ($duration) {
  expect(BattleFeedbackTiming::duration($duration))->toBe(0.0)
    ->and(BattleFeedbackTiming::progress(10.0, $duration, 10.0))->toBe(1.0);
})->with([0.0, -0.5, INF, -INF, NAN]);

it('preserves the supplied positive duration without a minimum hold', function () {
  expect(BattleFeedbackTiming::duration(0.0001))->toBe(0.0001)
    ->and(BattleFeedbackTiming::duration(2.75))->toBe(2.75);
});
