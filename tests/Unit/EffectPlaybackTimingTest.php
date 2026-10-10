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

it('uses the same exact frame boundaries for pose sampling and timeline traversal', function (int $fps) {
  $timing = new EffectPlaybackTiming(new CompiledEffectTimeline('shared-frame-clock', '', fps: $fps));
  for ($tick = 0; $tick <= 360; $tick++) {
    $seconds = $tick / 120;
    $expected = intdiv($tick * $fps, 120);
    expect(EffectPlaybackTiming::getFrameCountForElapsed($seconds, 1 / $fps))->toBe($expected)
      ->and($timing->getFrameCountAt($seconds))->toBe($expected);
  }
  for ($frame = 1; $frame <= 3; $frame++) {
    expect(EffectPlaybackTiming::getFrameCountForElapsed(($frame - 1e-8) / $fps, 1 / $fps))->toBe($frame - 1)
      ->and(EffectPlaybackTiming::getFrameCountForElapsed($frame / $fps, 1 / $fps))->toBe($frame);
  }
})->with(range(1, 120));

it('refuses invalid frame durations in shared stateless sampling', function (float $secondsPerFrame) {
  expect(fn() => EffectPlaybackTiming::getFrameCountForElapsed(1.0, $secondsPerFrame))
    ->toThrow(InvalidArgumentException::class, 'Frame duration must be finite and positive.');
})->with([0.0, -1.0, INF, -INF, NAN]);

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

it('uses an explicit phase budget for paced timelines in runtime and standalone inspection', function (float $duration) {
  $timeline = new CompiledEffectTimeline('phase-cadence', '', fps: 25,
    defaults: ['lengthFrames' => 6, 'cadence' => 'battle_phase']);
  $timing = EffectPlaybackTiming::createForBattlePhase($timeline, $duration);
  $session = new EffectPlaybackSession($timeline, false, phaseDurationSeconds: max(.01, $duration));
  expect($timing->secondsPerFrame)->toBe($session->secondsPerFrame)->toBe(max(.01, $duration) / 6)
    ->and($timing->durationSeconds)->toEqualWithDelta(max(.01, $duration), .000001)
    ->and($timing->getFrameBoundary(3, 120))->toBe((int)ceil(max(.01, $duration) * 60 - 1e-9));
  $session->update(max(.01, $duration));
  expect($session->isCompleted)->toBeTrue();
  $fixed = new CompiledEffectTimeline('fixed-cadence', '', fps: 25, defaults: ['lengthFrames' => 6]);
  expect(EffectPlaybackTiming::createForBattlePhase($fixed, $duration)->durationSeconds)->toBe(6 / 25);
})->with([0.0, .173, .72, 3.0]);

it('requires one explicit timing authority for paced standalone timelines', function () {
  $timeline = new CompiledEffectTimeline('phase-cadence', '', defaults: ['lengthFrames' => 6, 'cadence' => 'battle_phase']);
  expect(fn() => new EffectPlaybackTiming($timeline))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new EffectPlaybackSession($timeline))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new EffectPlaybackTiming($timeline, secondsPerFrame: .12, phaseDurationSeconds: .72))
    ->toThrow(InvalidArgumentException::class)
    ->and(new EffectPlaybackSession($timeline, false, 2.0, phaseDurationSeconds: .72)->secondsPerFrame)->toBe(.06);
});

it('refuses invalid phase budgets instead of inventing an authored rate', function (float $duration) {
  $timeline = new CompiledEffectTimeline('phase-cadence', '', defaults: ['cadence' => 'battle_phase']);
  expect(fn() => EffectPlaybackTiming::createForBattlePhase($timeline, $duration))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new EffectPlaybackTiming($timeline, phaseDurationSeconds: $duration))->toThrow(InvalidArgumentException::class);
})->with([-1.0, INF, -INF, NAN]);

it('refuses a phase budget for a fixed timeline', function () {
  $timeline = new CompiledEffectTimeline('fixed-cadence', '');
  expect(fn() => new EffectPlaybackTiming($timeline, phaseDurationSeconds: .72))->toThrow(InvalidArgumentException::class);
});
