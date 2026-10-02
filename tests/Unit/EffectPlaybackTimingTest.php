<?php

use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;

it('shares authored pacing and exact half-open boundaries with standalone playback', function () {
  $timeline = new CompiledEffectTimeline('speed', '', fps: 48,
    cueSchedule: [['id' => 'hit', 'frame' => 1, 'type' => 'applyEffect']],
    defaults: ['lengthFrames' => 7, 'playback' => ['defaultSpeed' => 2.0]]);
  $timing = new EffectPlaybackTiming($timeline);
  $session = new EffectPlaybackSession($timeline, false);
  expect($timing->fps)->toBe($session->fps)->toBe(48)
    ->and($timing->effectiveSpeed)->toBe($session->effectiveSpeed)->toBe(2.0)
    ->and($timing->totalFrames)->toBe($session->totalFrames)->toBe(7)
    ->and($timing->secondsPerFrame)->toBe($session->secondsPerFrame)->toBe(1 / 96)
    ->and($timing->durationSeconds)->toBe(7 / 96)
    ->and(array_map(fn(int $frame): int => $timing->getFrameBoundary($frame, 120), range(0, 7)))
    ->toBe([0, 2, 3, 4, 5, 7, 8, 9]);
  $session->update(1 / 120);
  expect($session->currentFrame)->toBe($timing->getFrameCountAt(1 / 120))->toBe(0);
  expect(array_column($session->update(1 / 120)->crossedCues, 'id'))->toBe(['hit'])
    ->and($session->currentFrame)->toBe($timing->getFrameCountAt(2 / 120))->toBe(1);
});

it('retains explicit speed precedence and the shared minimum speed', function (float $speed) {
  $timeline = new CompiledEffectTimeline('minimum', '', fps: 10,
    defaults: ['lengthFrames' => 4, 'playback' => ['defaultSpeed' => $speed]]);
  $timing = new EffectPlaybackTiming($timeline);
  expect($timing->effectiveSpeed)->toBe(.01)->and($timing->secondsPerFrame)->toBe(10.0)
    ->and($timing->durationSeconds)->toBe(40.0)->and($timing->getFrameBoundary(1, 120))->toBe(1200)
    ->and(new EffectPlaybackTiming($timeline, 2.0)->effectiveSpeed)->toBe(2.0);
})->with([0.0, -1.0, .001]);

it('rejects non-finite speed in both timing and standalone playback', function (float $speed) {
  $timeline = new CompiledEffectTimeline('invalid', '',
    defaults: ['playback' => ['defaultSpeed' => $speed]]);
  expect(fn() => new EffectPlaybackTiming($timeline))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new EffectPlaybackSession($timeline))->toThrow(InvalidArgumentException::class)
    ->and(new EffectPlaybackTiming($timeline, 1.0)->effectiveSpeed)->toBe(1.0);
})->with([INF, -INF, NAN]);

it('rejects an overflowing effective rate instead of creating a zero-duration clock', function () {
  $timeline = new CompiledEffectTimeline('overflow', '', fps: 120,
    defaults: ['playback' => ['defaultSpeed' => PHP_FLOAT_MAX]]);
  expect(fn() => new EffectPlaybackTiming($timeline))->toThrow(InvalidArgumentException::class,
    'Effective effect playback rate must be finite.');
});

it('keeps elapsed inspection side-effect free and rejects non-finite time', function () {
  $timing = new EffectPlaybackTiming(new CompiledEffectTimeline('inspection', '', fps: 24));
  expect($timing->getFrameCountAt(-1))->toBe(0)->and($timing->getFrameCountAt(1 / 24))->toBe(1)
    ->and(fn() => $timing->getFrameCountAt(INF))->toThrow(InvalidArgumentException::class);
});

it('preserves consumer-owned legacy frame durations without changing authored-speed behavior', function (float $seconds) {
  $timeline = new CompiledEffectTimeline('legacy-cadence', '', fps: 25, defaults: ['lengthFrames' => 6]);
  $timing = new EffectPlaybackTiming($timeline, secondsPerFrame: $seconds);
  $session = new EffectPlaybackSession($timeline, false, secondsPerFrame: $seconds);
  expect($timing->secondsPerFrame)->toBe($session->secondsPerFrame)->toBe($seconds)
    ->and($timing->durationSeconds)->toBe(6 * $seconds)
    ->and($timing->getFrameCountAt(3 * $seconds))->toBe(3)
    ->and($timing->getFrameBoundary(3, 120))->toBe((int)ceil(3 * $seconds * 120 - 1e-9))
    ->and(new EffectPlaybackTiming($timeline)->secondsPerFrame)->toBe(1 / 25);
  expect($session->update(3 * $seconds)->crossedFrames)->toBe([1, 2, 3]);
})->with([.12, .173, 300.0]);

it('rejects conflicting or invalid exact frame duration overrides', function (float $seconds) {
  $timeline = new CompiledEffectTimeline('invalid-cadence', '');
  expect(fn() => new EffectPlaybackTiming($timeline, secondsPerFrame: $seconds))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new EffectPlaybackSession($timeline, secondsPerFrame: $seconds))->toThrow(InvalidArgumentException::class);
})->with([0.0, -1.0, INF, -INF, NAN]);

it('refuses two competing timing authorities for one effect session', function () {
  $timeline = new CompiledEffectTimeline('conflicting-clock', '');
  expect(fn() => new EffectPlaybackTiming($timeline, 2.0, .12))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new EffectPlaybackSession($timeline, false, 2.0, .12))->toThrow(InvalidArgumentException::class);
});
