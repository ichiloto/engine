<?php

use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;

it('maps authored lanes to the exact production boundaries and visible command frames', function (int $fps, float $speed, bool $paced) {
  $effect = new CompiledEffectTimeline('mapped-effect', '', fps: $fps,
    playbackSegments: array_map(static fn(int $frame): array => [
      'startFrame' => $frame, 'endFrame' => $frame, 'layer' => 'image',
      'drawCommands' => [['trackId' => 'pose', 'payload' => ['sourceFrame' => $frame]]]], range(0, 6)),
    cueSchedule: [['id' => 'contact', 'type' => 'applyEffect', 'frame' => 3, 'payload' => []]],
    defaults: ['lengthFrames' => 7, 'cadence' => $paced ? 'battle_phase' : 'fixed',
      'playback' => ['defaultSpeed' => $speed]]);
  $timings = new BattleTurnTimings(.1, .1, .173, .72, .1, .1, .1);
  $plan = new BattleCommandTimeline($timings, source: $effect, target: $effect);
  foreach (['source' => $timings->actionAnimation, 'target' => $timings->effectAnimation] as $lane => $budget) {
    $clock = EffectPlaybackTiming::createForBattlePhase($effect, $budget);
    $phase = $plan->phases[$lane];
    foreach (range(0, 6) as $frame) {
      $offset = $clock->getFrameBoundary($frame, BattleCommandTimeline::FPS);
      $mapped = $plan->getCommandFrameForAuthoredFrame($lane, $frame);
      expect($mapped)->toBe($offset < $phase['length'] ? $phase['start'] + $offset : null);
      if ($mapped !== null) {
        // Pacing may skip drawings. Mapping is an honest boundary, not a promise to display each one.
        expect($plan->getAuthoredFrameAtCommandFrame($lane, $mapped))->toBeGreaterThanOrEqual($frame);
      }
    }
    foreach (range(0, $phase['length'] - 1) as $offset) {
      $at = $phase['start'] + $offset;
      $authored = $plan->getAuthoredFrameAtCommandFrame($lane, $at);
      expect($authored)->toBe(min(6, $clock->getFrameCountAt($offset / BattleCommandTimeline::FPS)));
      $segments = array_values(array_filter($plan->timeline->playbackSegments,
        static fn(array $segment): bool => $segment['startFrame'] <= $at && $at <= $segment['endFrame']));
      expect($segments)->toHaveCount(1)
        ->and($segments[0]['drawCommands'][0]['payload']['sourceFrame'])->toBe($authored);
    }
    expect($plan->getAuthoredFrameAtCommandFrame($lane, $phase['start'] - 1))->toBeNull()
      ->and($plan->getAuthoredFrameAtCommandFrame($lane, $phase['start'] + $phase['length']))->toBeNull()
      ->and($plan->getCommandFrameForAuthoredFrame($lane, -1))->toBeNull()
      ->and($plan->getCommandFrameForAuthoredFrame($lane, 7))->toBeNull();
  }
  $impact = array_find($plan->timeline->cueSchedule, static fn(array $cue): bool => $cue['type'] === 'commandImpact');
  expect($plan->getCommandFrameForAuthoredFrame('target', 3))->toBe($impact['frame']);
})->with([[23, 1.0, false], [48, 1.25, false], [7, 1.4, false], [120, 4.0, false],
  [24, .5, true], [24, 4.0, true]]);

it('maps independently paced Terminal fallback without extending the graphical authored lane', function () {
  $graphical = new CompiledEffectTimeline('image', '', fps: 12,
    defaults: ['lengthFrames' => 2, 'playback' => ['defaultSpeed' => 2.0]]);
  $terminal = new CompiledEffectTimeline('terminal', '', fps: 12,
    defaults: ['lengthFrames' => 4, 'playback' => ['defaultSpeed' => .5]]);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $graphical, terminalTarget: $terminal);
  $start = $plan->phases['target']['start'];
  expect($plan->getCommandFrameForAuthoredFrame('target', 1))->toBe($start + 5)
    ->and($plan->getCommandFrameForAuthoredFrame('terminal-target', 3))->toBe($start + 60)
    ->and($plan->getAuthoredFramesAtCommandFrame($start + 9))
    ->toBe(['source' => null, 'target' => 1, 'terminal-target' => 0])
    ->and($plan->getAuthoredFramesAtCommandFrame($start + 60))
    ->toBe(['source' => null, 'target' => null, 'terminal-target' => 3])
    ->and($plan->getAuthoredFrameAtCommandFrame('terminal-target', $start + 80))->toBeNull();
});

it('returns inactive mappings for unauthored stages and result-only plans and rejects unknown lanes', function () {
  foreach ([false, true] as $resultsOnly) {
    $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), resultsOnly: $resultsOnly);
    foreach (['source', 'target', 'terminal-target'] as $lane) {
      expect($plan->getCommandFrameForAuthoredFrame($lane, 0))->toBeNull()
        ->and($plan->getAuthoredFrameAtCommandFrame($lane, -1))->toBeNull()
        ->and($plan->getAuthoredFrameAtCommandFrame($lane, 0))->toBeNull();
    }
    expect(fn() => $plan->getCommandFrameForAuthoredFrame('not-a-lane', 0))->toThrow(InvalidArgumentException::class)
      ->and(fn() => $plan->getAuthoredFrameAtCommandFrame('not-a-lane', 0))->toThrow(InvalidArgumentException::class);
  }
});
