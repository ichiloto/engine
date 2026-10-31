<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCommandTimeline, BattleCommandPlayback, BattlePoseRole, BattlePoseSequence, BattlePoseSet, BattlerPose, BattleTravelPlan};
use Ichiloto\Engine\Entities\{Character, Stats};

it('separates stationary entry and exit sequences from constant-speed run and hop phases', function () {
  $asset = 'graphical-canvas/synthetic-320x180.png';
  $move = new BattlerPose($asset, columns: 8, frames: [2,3], fps: 10, entryFrames: [0,1], exitFrames: [4,5]);
  $hop = new BattlerPose($asset, columns: 8, frames: [3,4], fps: 10, loop: false, entryFrames: [6], exitFrames: [7]);
  $attack = new BattlerPose($asset, columns: 8, frames: [0,1,...array_fill(0, 12, 2)], fps: 10, loop: false, impactFrame: 1);
  $poses = new BattlePoseSet(['move' => $move, 'return' => $hop, 'attack' => $attack]);
  $travel = new BattleTravelPlan([[900,400],[400,400]],350,400,-1,500,1000);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1), actionPose: $attack,
    movementPoses: true, travel: $travel, movePose: $move, returnPose: $hop);
  expect($plan->phases['advance']['length'])->toBe(120)->and($plan->phases['return']['length'])->toBe(60)
    ->and($plan->phases['recovery']['length'])->toBeGreaterThan(0);
  $actor = new Character('Synthetic', 1, new Stats(currentHp: 100, totalHp: 100));
  $hits = 0;
  $playback = new BattleCommandPlayback($plan, $actor, [], BattlePoseRole::ATTACK, function () use (&$hits) { $hits++; }, static fn() => null);
  $playback->begin();
  $root = dirname(__DIR__) . '/Fixtures/Renderer';
  foreach ([
    ['enter-advance', BattlePoseRole::MOVE, BattlePoseSequence::ENTRY, 0, 0.0],
    ['advance', BattlePoseRole::MOVE, BattlePoseSequence::MAIN, 2, 0.0],
    ['exit-advance', BattlePoseRole::MOVE, BattlePoseSequence::EXIT, 4, -500.0],
    ['enter-return', BattlePoseRole::RETURNING, BattlePoseSequence::ENTRY, 6, -500.0],
    ['return', BattlePoseRole::RETURNING, BattlePoseSequence::MAIN, 3, -500.0],
    ['exit-return', BattlePoseRole::RETURNING, BattlePoseSequence::EXIT, 7, 0.0],
  ] as [$phase,$role,$sequence,$cell,$x]) {
    $playback->update(($plan->phases[$phase]['start'] - $playback->session->currentFrame) / 120);
    expect($playback->phase)->toBe($phase)->and($playback->getPoseRole($actor,$poses))->toBe($role)
      ->and($playback->getPoseSequence($actor,$role))->toBe($sequence)
      ->and($playback->getTravelOffset()->x)->toEqualWithDelta($x, 1e-6);
    $pose = $poses->getPose($role);
    $art = $pose->getArtwork($root, $playback->getPoseElapsedSeconds($actor,$role),
      durationSeconds: $playback->getPoseDurationSeconds($actor,$role), sequence: $sequence);
    expect($art->sourceRect->x)->toBe($cell * 40);
    $playback->pause(); $before = $playback->session->currentFrame; $playback->update(5);
    expect($playback->session->currentFrame)->toBe($before); $playback->resume();
    if ($phase === 'advance') {
      $playback->update(.5);
      expect($playback->getTravelOffset()->x)->toEqualWithDelta(-250, 1e-6)
        ->and($playback->getPoseSequence($actor,$role))->toBe(BattlePoseSequence::MAIN);
    }
    if ($phase === 'return') {
      $playback->update(.25); expect($playback->getTravelOffset()->x)->toEqualWithDelta(-250, 1e-6);
    }
  }
  $playback->update(10);
  expect($hits)->toBe(1)->and($playback->getTravelOffset()->x)->toBe(0.0)
    ->and($playback->getPoseRole($actor,$poses))->toBe(BattlePoseRole::IDLE);
});

it('keeps omitted transitions and nonmovement timelines on their original clocks', function () {
  $timings = new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1);
  $pose = new BattlerPose('pose.png', entryFrames: [0], exitFrames: [0]);
  $original = new BattleCommandTimeline($timings);
  $disabled = new BattleCommandTimeline($timings, movePose: $pose, returnPose: $pose);
  $empty = new BattleCommandTimeline($timings, movementPoses: true, movePose: new BattlerPose('pose.png'));
  expect($disabled->phases)->toBe($original->phases)
    ->and(array_filter(array_keys($empty->phases), static fn($phase) => str_starts_with($phase,'enter-') || str_starts_with($phase,'exit-')))->toBe([]);
});

it('cancels or yields to knockout during stationary entry without leaving a travel offset', function () {
  $actor = new Character('Synthetic', 1, new Stats(currentHp: 100, totalHp: 100));
  $move = new BattlerPose('pose.png', entryFrames: [0,0,0]);
  $poses = new BattlePoseSet(['move' => $move]);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1), movementPoses:true, movePose:$move);
  $playback = new BattleCommandPlayback($plan,$actor,[],BattlePoseRole::ATTACK,static fn() => null,static fn() => null);
  $playback->begin(); $playback->update(.1);
  expect($playback->phase)->toBe('enter-advance')->and($playback->getAdvanceFraction())->toBe(0.0);
  $actor->stats->currentHp=0;
  expect($playback->getPoseRole($actor,$poses))->toBe(BattlePoseRole::KNOCKOUT);
  $actor->stats->currentHp=100; $playback->cancel();
  expect($playback->getPoseRole($actor,$poses))->toBe(BattlePoseRole::IDLE)->and($playback->getAdvanceFraction())->toBe(0.0);
});
