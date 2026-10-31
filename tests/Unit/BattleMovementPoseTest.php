<?php

use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCommandPlayback, BattleCommandTimeline, BattlerPose, BattlePoseRole, BattlePoseSet};
use Ichiloto\Engine\Entities\{Character, Stats};

it('finishes anchored recovery before an authored walk home without moving combat impact', function (float $pace) {
  $pose = new BattlerPose('pose.png', columns: 4, frames: [0,1,2,...array_fill(0, 17, 3)], fps: 20, loop: false, impactFrame: 2);
  $timings = new BattleTurnTimings(.2, .1, .1, .1, .1, .2, .1);
  $ordinary = new BattleCommandTimeline($timings, actionPose: $pose);
  $plan = new BattleCommandTimeline($timings, actionPose: $pose, movementPoses: true);
  foreach (['advance', 'announce', 'source', 'target', 'reaction'] as $phase) {
    expect($plan->phases[$phase])->toBe($ordinary->phases[$phase]);
  }
  expect($plan->phases['return']['start'])->toBe($plan->actionPoseEndFrame)
    ->and(array_find($plan->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame'])
    ->toBe(array_find($ordinary->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame']);
  $actor = new Character('Synthetic', 1, new Stats(currentHp: 100, totalHp: 100));
  $set = new BattlePoseSet(['attack' => $pose, 'move' => new BattlerPose('walk.png')]);
  $hits = 0;
  $playback = new BattleCommandPlayback($plan, $actor, [], BattlePoseRole::ATTACK, function () use (&$hits) { $hits++; }, static fn() => null);
  $playback->begin();
  $recovery = $plan->phases['recovery']['start'];
  $playback->update($recovery / 120);
  expect($playback->getPoseRole($actor, $set))->toBe(BattlePoseRole::ATTACK)
    ->and($playback->getAdvanceFraction())->toBe(1.0)->and($hits)->toBe(1);
  $playback->update(($plan->phases['return']['start'] - $recovery) / 120);
  expect($playback->getPoseRole($actor, $set))->toBe(BattlePoseRole::MOVE)
    ->and($playback->getPoseElapsedSeconds($actor, BattlePoseRole::MOVE))->toBe(0.0);
  while (!$playback->isCompleted) { $playback->update($pace); }
  expect($playback->getPoseRole($actor, $set))->toBe(BattlePoseRole::IDLE)
    ->and($playback->getAdvanceFraction())->toBe(0.0)->and($hits)->toBe(1);
})->with([1 / 120, .075, 4.0]);

it('keeps movement artwork optional and yields to lifecycle and reduced-motion state', function () {
  $actor = new Character('Synthetic', 1, new Stats(currentHp: 100, totalHp: 100));
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.2, .1, .1, .1, .1, .2, .1), movementPoses: true);
  $playback = new BattleCommandPlayback($plan, $actor, [], BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
  $set = new BattlePoseSet(['move' => new BattlerPose('walk.png')]);
  expect($playback->getPoseRole($actor))->toBe(BattlePoseRole::IDLE)
    ->and($playback->getPoseRole($actor, $set, true))->toBe(BattlePoseRole::IDLE)
    ->and($playback->getPoseRole($actor, $set))->toBe(BattlePoseRole::MOVE);
  $playback->pause(); $playback->update(1);
  expect($playback->getPoseElapsedSeconds($actor, BattlePoseRole::MOVE))->toBe(0.0);
  $playback->resume(); $playback->update(.05);
  expect($playback->getPoseElapsedSeconds($actor, BattlePoseRole::MOVE))->toBe(.05);
  $actor->stats->currentHp = 0;
  expect($playback->getPoseRole($actor, $set))->toBe(BattlePoseRole::KNOCKOUT);
  $actor->stats->currentHp = 100;
  $playback->cancel();
  expect($playback->getPoseRole($actor, $set))->toBe(BattlePoseRole::IDLE);
});

it('requires movement roles to loop without an impact anchor', function (string $role) {
  expect(fn() => new BattlePoseSet([$role => new BattlerPose('walk.png', loop: false)]))
    ->toThrow(InvalidArgumentException::class);
  expect(new BattlePoseSet([$role => new BattlerPose('walk.png')]))->toBeInstanceOf(BattlePoseSet::class);
})->with(['move', 'return']);
