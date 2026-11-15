<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\Timelines\{EffectTimelineValidator, EffectPresentation};
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCommandPlayback, BattleCommandTimeline, BattleFieldMotion, BattleFieldTransform, BattlePoseRole};
use Ichiloto\Engine\Entities\{Character, Stats};
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneTrack;

function fieldMotionData(): array
{
  return ['fps' => 24, 'lengthFrames' => 6, 'tracks' => [['id' => 'camera', 'type' => 'field-motion',
    'keyframes' => [['frame' => 0, 'duration' => 2, 'payload' => ['x' => -10.25, 'y' => 3.5]],
      ['frame' => 2, 'payload' => ['x' => 4, 'y' => -2]],
      ['frame' => 3, 'visible' => false, 'payload' => ['x' => 40, 'y' => 40]],
      ['frame' => 4, 'payload' => ['x' => 0, 'y' => 0]]]]]];
}

it('preserves fractional authored field samples through the shared source track round trip', function () {
  $data = fieldMotionData();
  $track = SummonCutsceneTrack::fromArray($data['tracks'][0])->toArray();
  expect($track['keyframes'][0]['payload'])->toBe(['x' => -10.25, 'y' => 3.5]);
  $data['tracks'] = [$track];
  $effect = (new EffectTimelineValidator(''))->compile('field', $data, forBattle: true);
  expect($effect->playbackSegments)->toHaveCount(4)
    ->and($effect->playbackSegments[0]['startFrame'])->toBe(0)
    ->and($effect->playbackSegments[0]['endFrame'])->toBe(1)
    ->and($effect->playbackSegments[0]['presentation'])->toBe('graphical')
    ->and($effect->playbackSegments[0]['drawCommands'][0]['payload']['x'])->toBe(-10.25)
    ->and($effect->cueSchedule)->toBe([]);
});

it('rejects malformed offsets and held sample intervals', function (Closure $change) {
  $data = fieldMotionData(); $change($data);
  expect(fn() => (new EffectTimelineValidator(''))->compile('bad', $data, forBattle: true))
    ->toThrow(InvalidArgumentException::class);
})->with([
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['x'] = NAN],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['y'] = INF],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['x'] = '10'],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['x'] = true],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['x'] = 16385],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload']['amplitude'] = 2],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['payload'] = null],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['duration'] = 0],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['duration'] = 7],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][1]['frame'] = 1],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['frame'] = -1],
  [fn(array &$d) => $d['tracks'][0]['keyframes'][0]['visible'] = 1],
  [fn(array &$d) => $d['tracks'][0]['presentation'] = 'all'],
  [fn(array &$d) => $d['playback'] = 'loop'],
]);

it('keeps graphical field motion out of Terminal and ambient timelines', function () {
  $validator = new EffectTimelineValidator(''); $data = fieldMotionData();
  $terminal = $validator->compile('terminal', $data, forBattle: true, presentation: EffectPresentation::TERMINAL);
  expect($terminal->playbackSegments)->toBe([])
    ->and(fn() => $validator->compile('ambient', $data))->toThrow(InvalidArgumentException::class);
  unset($data['fps']); $data['cadence'] = 'battle-phase';
  expect(fn() => $validator->compile('phase', $data, forBattle: true))->toThrow(InvalidArgumentException::class);
});

it('samples the actual command clock without changing pause, outcomes or target identity', function () {
  $effect = (new EffectTimelineValidator(''))->compile('field', fieldMotionData(), forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1), target: $effect);
  $actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100)); $hits = 0;
  $playback = new BattleCommandPlayback($plan, $actor, [$actor], BattlePoseRole::ATTACK,
    function () use (&$hits) { $hits++; }, static fn() => null);
  $playback->begin(); $start = $plan->phases['target']['start'];
  $playback->update($start / 120);
  $frame = $playback->session->currentFrame;
  $expectations = [0 => [-10.25, 3.5], 1 => [-10.25, 3.5], 2 => [4.0, -2.0], 3 => null, 4 => [0.0, 0.0], 5 => null];
  foreach ([4,0,5,2,1,3,0] as $sample) {
    $inspection = new BattleCommandPlayback($plan, $actor, [$actor], BattlePoseRole::ATTACK,
      static fn() => null, static fn() => null);
    $inspection->begin();
    $inspection->update(($start + $sample * 5) / 120);
    $offset = BattleFieldMotion::getOffset($inspection, false);
    expect($offset === null ? null : [$offset->x, $offset->y])->toBe($expectations[$sample]);
  }
  expect(BattleFieldMotion::getOffset($playback, true))->toBeNull()
    ->and($playback->session->currentFrame)->toBe($frame)->and($hits)->toBe(0)
    ->and($actor->stats->currentHp)->toBe(100)->and($playback->targets)->toBe([$actor]);
  $playback->cancel(); expect(BattleFieldMotion::getOffset($playback, false))->toBeNull();
});

it('keeps field motion independent of visual clears and respects pause and presentation scope', function () {
  $data = fieldMotionData();
  $data['tracks'][] = ['id' => 'clear', 'type' => 'glyph', 'presentation' => 'graphical',
    'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => '.', 'payload' => ['clearBeforeDraw' => true]]]];
  $data['tracks'][] = ['id' => 'second-motion', 'type' => 'field-motion',
    'keyframes' => [['frame' => 0, 'duration' => 2, 'payload' => ['x' => 2, 'y' => -.5]]]];
  $effect = (new EffectTimelineValidator(''))->compile('clear', $data, forBattle: true);
  $actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1), target: $effect);
  $playback = new BattleCommandPlayback($plan, $actor, [$actor], BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
  $playback->begin(); $playback->update($plan->phases['target']['start'] / 120);
  $offset = BattleFieldMotion::getOffset($playback, false);
  expect([$offset->x, $offset->y])->toBe([-8.25, 3.0]);
  $frame = $playback->session->currentFrame; $playback->pause(); $playback->update(10);
  expect($playback->session->currentFrame)->toBe($frame)
    ->and(BattleFieldMotion::getOffset($playback, false))->toEqual($offset);
  $playback->resume(); $playback->update(10 / 120);
  expect(BattleFieldMotion::getOffset($playback, false))->toEqual(new \Ichiloto\Engine\Core\Vector2(4, -2));
  $playback->update(10); expect(BattleFieldMotion::getOffset($playback, false))->toBeNull();
});


it('preserves authored field scale samples through source round trips and the paused command clock', function () {
  $data = fieldMotionData(); $data['tracks'][0]['keyframes'][0]['payload']['scale'] = 1.25;
  $data['tracks'][0]['keyframes'][1]['payload']['scale'] = 1.125;
  $roundTrip = SummonCutsceneTrack::fromArray($data['tracks'][0])->toArray();
  expect($roundTrip['keyframes'][0]['payload']['scale'])->toBe(1.25)
    ->and($roundTrip['keyframes'][3]['payload'])->not->toHaveKey('scale');
  $effect = (new EffectTimelineValidator(''))->compile('field-scale', $data, forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1), target: $effect);
  $actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  foreach ([0 => 1.25, 2 => 1.125, 4 => 1.0] as $frame => $scale) {
    $playback = new BattleCommandPlayback($plan, $actor, [$actor], BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
    $playback->begin(); $playback->update(($plan->phases['target']['start'] + 5 * $frame) / 120);
    $sample = BattleFieldMotion::getTransform($playback, false);
    expect($sample->scale)->toBe($scale)->and(BattleFieldMotion::getTransform($playback, true))->toBeNull();
    $playback->pause(); $playback->update(10); expect(BattleFieldMotion::getTransform($playback, false))->toEqual($sample);
    $playback->cancel(); expect(BattleFieldMotion::getTransform($playback, false))->toBeNull();
  }
});

it('refuses invalid scale at the authored sample boundary', function (mixed $factor) {
  $data = fieldMotionData(); $data['tracks'][0]['keyframes'][0]['payload']['scale'] = $factor;
  expect(fn() => (new EffectTimelineValidator(''))->compile('bad-scale', $data, forBattle: true))->toThrow(InvalidArgumentException::class);
})->with([0, -1, NAN, INF, 8.00001, '1.2', true, null]);

it('checks geometric coverage at exact precision without hiding subpixel gaps or rotated corners', function () {
  $rect = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle(0, 0, 1350, 720);
  $background = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage('background', 'arena.png', $rect);
  (new BattleFieldTransform(12, -7, 1 + 14 / 720))->assertCoverage($background, 1350, 720);
  (new BattleFieldTransform(-12, 7, 1 + 14 / 720))->assertCoverage($background, 1350, 720);
  expect(fn() => (new BattleFieldTransform(12, -7, 1.0194))->assertCoverage($background, 1350, 720))
    ->toThrow(InvalidArgumentException::class, 'uncovered')
    ->and(fn() => (new BattleFieldTransform(1, 0))->assertCoverage($background, 1350, 720))->toThrow(InvalidArgumentException::class, 'uncovered')
    ->and(fn() => (new BattleFieldTransform(0, 0, .99))->assertCoverage($background, 1350, 720))->toThrow(InvalidArgumentException::class, 'uncovered');
  $rotated = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage('rotated', 'arena.png', $rect,
    rotation: 45, pivot: new \Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot(.5, .5));
  expect(fn() => (new BattleFieldTransform(0, 0))->assertCoverage($rotated, 1350, 720))->toThrow(InvalidArgumentException::class, 'uncovered');
  $clipped = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage('clipped', 'arena.png', $rect,
    clipRect: new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle(1, 1, 1348, 718));
  expect(fn() => (new BattleFieldTransform(0, 0))->assertCoverage($clipped, 1350, 720))->toThrow(InvalidArgumentException::class, 'uncovered');
});


it('combines simultaneous scale contributions without changing their source or command outcome', function () {
  $data = fieldMotionData(); $data['tracks'][0]['keyframes'][0]['payload']['scale'] = 1.2;
  $data['tracks'][] = ['id' => 'second-scale', 'type' => 'field-motion',
    'keyframes' => [['frame' => 0, 'duration' => 2, 'payload' => ['x' => 2, 'y' => -.5, 'scale' => 1.25]]]];
  $actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  $effect = (new EffectTimelineValidator(''))->compile('combined-scale', $data, forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1), target: $effect);
  $hits = 0; $playback = new BattleCommandPlayback($plan, $actor, [$actor], BattlePoseRole::ATTACK,
    static function () use (&$hits): void { ++$hits; }, static fn() => null);
  $playback->begin(); $playback->update($plan->phases['target']['start'] / 120);
  $sample = BattleFieldMotion::getTransform($playback, false);
  expect([$sample->x, $sample->y, $sample->scale])->toBe([-8.25, 3.0, 1.5])
    ->and($hits)->toBe(0)->and($actor->stats->currentHp)->toBe(100);
  $playback->update(10); expect(BattleFieldMotion::getTransform($playback, false))->toBeNull()->and($hits)->toBe(1);
});
