<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\Timelines\{EffectTimelineValidator, EffectPresentation};
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCommandPlayback, BattleCommandTimeline, BattleBattlerMotion, BattlePoseRole};
use Ichiloto\Engine\Entities\{Character, Stats};
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneTrack;

function battlerMotionData(): array
{
  return ['fps' => 24, 'lengthFrames' => 6, 'tracks' => [['id' => 'recoil', 'type' => 'battler-motion',
    'anchor' => 'target', 'space' => 'opponents', 'keyframes' => [
      ['frame' => 0, 'duration' => 2, 'payload' => ['x' => -10.25, 'y' => 3.5]],
      ['frame' => 2, 'payload' => ['x' => 4, 'y' => -2]],
      ['frame' => 3, 'visible' => false, 'payload' => ['x' => 40, 'y' => 40]],
      ['frame' => 4, 'payload' => ['x' => 0, 'y' => 0]],
    ]]]];
}

it('preserves authored motion through source editing and excludes Terminal and ambient playback', function () {
  $data = battlerMotionData();
  $data['tracks'][0] = SummonCutsceneTrack::fromArray($data['tracks'][0])->toArray();
  expect($data['tracks'][0]['anchor'])->toBe('target')->and($data['tracks'][0]['space'])->toBe('opponents')
    ->and($data['tracks'][0]['keyframes'][0]['payload'])->toBe(['x' => -10.25, 'y' => 3.5]);
  $validator = new EffectTimelineValidator('');
  $effect = $validator->compile('recoil', $data, forBattle: true);
  expect($effect->playbackSegments)->toHaveCount(4)->and($effect->cueSchedule)->toBe([])
    ->and($effect->playbackSegments[0]['drawCommands'][0]['payload'])->toBe([
      'x' => -10.25, 'y' => 3.5, 'anchor' => 'target', 'space' => 'opponents']);
  expect($validator->compile('terminal', $data, forBattle: true, presentation: EffectPresentation::TERMINAL)->playbackSegments)->toBe([])
    ->and(fn() => $validator->compile('ambient', $data))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $validator->compile('summon', $data, forSummon: true))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid motion contracts at compilation', function (Closure $change) {
  $data = battlerMotionData(); $change($data);
  expect(fn() => (new EffectTimelineValidator(''))->compile('bad', $data, forBattle: true))
    ->toThrow(InvalidArgumentException::class);
})->with([
  [fn(array &$d) => $d['tracks'][0]['anchor'] = 'screen'],
  [fn(array &$d) => $d['tracks'][0]['space'] = 'slots'],
  [fn(array &$d) => $d['tracks'][0]['presentation'] = 'all'],
  [fn(array &$d) => $d['tracks'][0]['keyframes'] = null],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['x'] = INF],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['y'] = NAN],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['x'] = '10'],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['y'] = 16385],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['amplitude'] = 1],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['duration'] = 0],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['duration'] = 7],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][1]['frame'] = 1],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['visible'] = 1],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['zIndex'] = 1],
  [fn(array &$d) => $d['playback'] = 'loop'],
  [function(array &$d) { unset($d['fps']); $d['cadence'] = 'battle-phase'; }],
]);

it('samples stable recipient identity once with side-relative x and absolute y', function (string $anchor, string $space) {
  $data = battlerMotionData(); $data['tracks'][0]['anchor'] = $anchor; $data['tracks'][0]['space'] = $space;
  $effect = (new EffectTimelineValidator(''))->compile('motion', $data, forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1), target: $effect);
  $actor = new Character('Twin', 1, new Stats(currentHp: 100, totalHp: 100));
  $target = new Character('Twin', 1, new Stats(currentHp: 100, totalHp: 100));
  $other = new Character('Twin', 1, new Stats(currentHp: 100, totalHp: 100));
  $subject = $anchor === 'caster' ? $actor : $target;
  $hits = 0;
  foreach ([4,0,5,2,1,3,0] as $frame) {
    $p = new BattleCommandPlayback($plan, $actor, [$target, $target], BattlePoseRole::ATTACK,
      function () use (&$hits) { $hits++; }, static fn() => null);
    $p->begin(); $p->update(($plan->phases['target']['start'] + $frame * 5) / 120);
    $expected = [0 => [-10.25, 3.5], 1 => [-10.25, 3.5], 2 => [4.0,-2.0], 3 => null, 4 => [0.0,0.0], 5 => null][$frame];
    foreach ([true, false] as $partySide) {
      $offset = BattleBattlerMotion::getOffset($p, $subject, $partySide, false);
      if ($expected !== null) {
        expect([$offset->x, $offset->y])->toBe([$expected[0] * ($space === 'opponents' && $partySide ? -1 : 1), $expected[1]]);
      } else { expect($offset)->toBeNull(); }
    }
    expect(BattleBattlerMotion::getOffset($p, $other, true, false))->toBeNull()
      ->and(BattleBattlerMotion::getOffset($p, $subject, true, true))->toBeNull();
    $before = $p->session->currentFrame; $p->pause(); $p->update(10);
    expect($p->session->currentFrame)->toBe($before);
    $p->resume(); $p->cancel();
    expect(BattleBattlerMotion::getOffset($p, $subject, true, false))->toBeNull();
  }
  expect($hits)->toBe(0)->and($actor->stats->currentHp)->toBe(100)->and($target->stats->currentHp)->toBe(100);
})->with(['caster','target'])->with(['canvas','opponents']);
