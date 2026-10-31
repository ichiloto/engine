<?php

use Ichiloto\Engine\Animations\{Animation, SourceEffectAnchor};
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCommandPlayback, BattleCommandTimeline, BattlerPose, BattlePoseRole, BattlePoseSet};
use Ichiloto\Engine\Entities\{Character, Stats};

it('anchors the instant pose strike and caster effect to target start across announcement budgets', function (float $announcement, bool $show, int $fps) {
  $source = new CompiledEffectTimeline('crescent', '', fps: $fps, playbackSegments: [[
    'startFrame' => 0, 'endFrame' => 0, 'layer' => 'image',
    'drawCommands' => [['trackId' => 'slash', 'payload' => []]],
  ], ['startFrame' => 1, 'endFrame' => 1, 'layer' => 'image',
    'drawCommands' => [['trackId' => 'shards', 'payload' => []]],
  ]], cueSchedule: [['id' => 'swing', 'type' => 'playSound', 'frame' => 0, 'payload' => []]],
    defaults: ['lengthFrames' => 3, 'restFrame' => 2]);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, $announcement, .01, .02, .4, .4, .1),
    source: $source, showAnnouncement: $show, sourceEffectAnchor: SourceEffectAnchor::TARGET);
  $actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  $target = new Character('Target', 1, new Stats(currentHp: 100, totalHp: 100));
  $hits = $sounds = 0;
  $playback = new BattleCommandPlayback($plan, $actor, [$target], BattlePoseRole::ATTACK,
    function () use (&$hits) { $hits++; }, function (array $cue) use (&$sounds) {
      if ($cue['type'] === 'playSound') { $sounds++; }
    });
  $pose = new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 4, frames: [0,1,1,2,2,3],
    fps: $fps, loop: false, restFrame: 0, impactFrame: 3);
  $poses = new BattlePoseSet(['attack' => $pose]);
  $root = dirname(__DIR__) . '/Fixtures/Renderer';
  $start = $plan->phases['target']['start'];
  expect(array_find($plan->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame'])->toBe($start + 3)
    ->and($plan->getCommandFrameForAuthoredFrame('source', 0))->toBe($start)
    ->and($plan->getAuthoredFrameAtCommandFrame('source', $start - 1))->toBeNull();
  $playback->update(($start - 1) / 120);
  $art = $pose->getArtwork($root, $playback->getPoseElapsedSeconds($actor), impactAtSeconds: $playback->getPoseImpactSeconds($actor));
  expect($art->sourceRect->x)->toBeLessThan(160)
    ->and($playback->getActiveSegments())->toBe([])->and($sounds)->toBe(0)->and($hits)->toBe(0);
  $playback->update(1 / 120);
  $art = $pose->getArtwork($root, $playback->getPoseElapsedSeconds($actor), impactAtSeconds: $playback->getPoseImpactSeconds($actor));
  expect($art->sourceRect->x)->toBe(160)->and($sounds)->toBe(1)->and($hits)->toBe(0)
    ->and($playback->getActiveSegments()[0]['drawCommands'][0]['trackId'])->toBe('source-slash')
    ->and($playback->getActiveSegments()[0]['drawCommands'][0]['payload']['anchor'])->toBe('caster');
  // Inspection is pure; pause freezes pose, effects and sounds on the same logical playhead.
  $playback->pause(); $playback->update(1);
  expect($playback->getPoseElapsedSeconds($actor))->toBe(($start - $plan->phases['announce']['start']) / 120)
    ->and($sounds)->toBe(1);
  $playback->resume();
  $next = $plan->getCommandFrameForAuthoredFrame('source', 1);
  $playback->update(($next - $start) / 120);
  expect($playback->getActiveSegments()[0]['drawCommands'][0]['trackId'])->toBe('source-shards');
  $return = $plan->phases['return']['start'];
  $playback->update(($return - $next) / 120);
  expect($playback->getPoseRole($actor, $poses))->toBe(BattlePoseRole::ATTACK)
    ->and($playback->getPoseRole($actor))->toBe(BattlePoseRole::IDLE)
    ->and($playback->getPoseImpactSeconds($target))->toBeNull()
    ->and($hits)->toBe(1)->and($sounds)->toBe(1);
  $playback->update(10);
  expect($playback->getPoseRole($actor, $poses))->toBe(BattlePoseRole::IDLE)->and($hits)->toBe(1);
})->with([0.0, .2, 4.0])->with([true, false])->with([11, 20, 120]);

it('holds natural anticipation for long lead-ins and preserves recovery speed', function () {
  $pose = new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 4, frames: [0,1,2,3],
    fps: 10, loop: false, impactFrame: 2);
  $root = dirname(__DIR__) . '/Fixtures/Renderer';
  foreach ([.1, 1.0, 1.999] as $elapsed) {
    expect($pose->getArtwork($root, $elapsed, impactAtSeconds: 2)->sourceRect->x)->toBe(80);
  }
  expect($pose->getArtwork($root, 2, impactAtSeconds: 2)->sourceRect->x)->toBe(160)
    ->and($pose->getArtwork($root, 2.1, impactAtSeconds: 2)->sourceRect->x)->toBe(240)
    ->and($pose->getArtwork($root, .049, impactAtSeconds: .05)->sourceRect->x)->toBe(80)
    ->and($pose->getArtwork($root, .05, impactAtSeconds: .05)->sourceRect->x)->toBe(160)
    ->and($pose->getArtwork($root, 2, true, impactAtSeconds: 2)->sourceRect->x)->toBe(0);
});

it('holds a rest frame before a zero-index strike and leaves unanchored poses unchanged', function () {
  $root = dirname(__DIR__) . '/Fixtures/Renderer';
  $pose = new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 4, frames: [2,3],
    fps: 10, loop: false, impactFrame: 0);
  expect($pose->getArtwork($root, .9, impactAtSeconds: 1)->sourceRect->x)->toBe(0)
    ->and($pose->getArtwork($root, 1, impactAtSeconds: 1)->sourceRect->x)->toBe(160)
    ->and($pose->getArtwork($root, .1)->sourceRect->x)->toBe(240);
  foreach ([-1, 2] as $invalid) {
    expect(fn() => new BattlerPose('pose.png', frames: [0,0], loop: false, impactFrame: $invalid))
      ->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new BattlerPose('pose.png', impactFrame: 0))->toThrow(InvalidArgumentException::class);
});

it('round trips the explicit caster effect anchor while preserving old animation data', function () {
  $old = new Animation(1, 'Synthetic');
  expect($old->toArray())->not->toHaveKey('sourceEffectAnchor');
  $bound = Animation::fromArray([...$old->toArray(), 'sourceEffect' => 'crescent', 'sourceEffectAnchor' => 'target']);
  expect($bound->sourceEffectAnchor)->toBe(SourceEffectAnchor::TARGET)
    ->and(Animation::fromArray($bound->toArray())->sourceEffectAnchor)->toBe(SourceEffectAnchor::TARGET);
  expect(fn() => Animation::fromArray([...$old->toArray(), 'sourceEffectAnchor' => 'invented']))->toThrow(ValueError::class);
});


it('waits for full anchored recovery without changing movement, announcement, effects or damage timing', function (float $announcement, bool $show) {
  $pose = new BattlerPose('pose.png', columns: 4, frames: [0,1,1,...array_fill(0, 17, 2),3],
    fps: 20, loop: false, impactFrame: 3);
  $timings = new BattleTurnTimings(.1, $announcement, .1, .1, .1, .1, .1);
  $ordinary = new BattleCommandTimeline($timings, showAnnouncement: $show);
  $plan = new BattleCommandTimeline($timings, showAnnouncement: $show, actionPose: $pose);
  expect($pose->getRecoverySeconds())->toBe(.9);
  foreach (['advance','announce','source','target','reaction','return'] as $phase) {
    expect($plan->phases[$phase])->toBe($ordinary->phases[$phase]);
  }
  expect(array_column($plan->timeline->cueSchedule, 'frame', 'id'))
    ->toBe(array_column($ordinary->timeline->cueSchedule, 'frame', 'id'))
    ->and($plan->actionPoseEndFrame)->toBe($plan->phases['target']['start'] + 108)
    ->and($plan->timeline->defaults['lengthFrames'])->toBe($plan->actionPoseEndFrame)
    ->and((new BattleCommandTimeline($timings, actionPose: new BattlerPose('still.png')))->actionPoseEndFrame)->toBeNull()
    ->and((new BattleCommandTimeline($timings, resultsOnly: true, actionPose: $pose))->actionPoseEndFrame)->toBeNull();
})->with([0.0, .2, 4.0])->with([false, true]);
