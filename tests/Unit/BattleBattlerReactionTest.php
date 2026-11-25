<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\Timelines\{EffectPresentation, EffectTimelineValidator};
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCommandPlayback, BattleCommandTimeline, BattlePoseRole, BattleTargetContactSequence};
use Ichiloto\Engine\Entities\{Character, Stats};

function battleReactionData(): array
{
  return [
    'fps' => 24,
    'lengthFrames' => 12,
    'effectTiming' => ['mode' => 'frame', 'frame' => 2],
    'tracks' => [[
      'id' => 'reaction',
      'type' => 'battler-reaction',
      'role' => 'damage',
      'keyframes' => [['frame' => 4, 'duration' => 2], ['frame' => 8, 'duration' => 2]],
    ]],
  ];
}

it('restarts a selected body pose on its own visual windows without resolving more combat', function () {
  $validator = new EffectTimelineValidator('');
  $data = battleReactionData();
  $effect = $validator->compile('stagger', $data, forBattle: true);
  expect(BattleTargetContactSequence::canUseTrack($data['tracks'][0]))->toBeTrue()
    ->and($effect->cueSchedule)->toBe([])
    ->and($validator->compile('stagger', $data, forBattle: true, presentation: EffectPresentation::TERMINAL)->playbackSegments)->toBe([]);
  $timings = new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1);
  $plan = new BattleCommandTimeline($timings, target: $effect);
  $actor = new Character('Twin', 1, new Stats(currentHp: 100, totalHp: 100));
  $target = new Character('Twin', 1, new Stats(currentHp: 100, totalHp: 100));
  $bystander = new Character('Twin', 1, new Stats(currentHp: 100, totalHp: 100));
  $hits = 0;
  $playback = new BattleCommandPlayback($plan, $actor, [$target], BattlePoseRole::ATTACK,
    static function () use (&$hits): void { ++$hits; }, static fn() => null);
  $playback->begin();
  $previous = 0;
  foreach ([3 => null, 4 => 0., 5 => 1 / 24, 6 => null, 8 => 0., 9 => 1 / 24, 10 => null] as $frame => $seconds) {
    $tick = $plan->getCommandFrameForAuthoredFrame('target', $frame);
    $playback->update(($tick - $previous) / BattleCommandTimeline::FPS);
    $expected = $seconds === null ? null : ['role' => BattlePoseRole::DAMAGE, 'seconds' => $seconds];
    expect($hits)->toBe(1)
      ->and($playback->getAuthoredReaction($target, false))->toBe($expected)
      ->and($playback->getAuthoredReaction($target, true))->toBeNull()
      ->and($playback->getAuthoredReaction($bystander, false))->toBeNull();
    $playback->pause();
    $playback->update(1);
    expect($playback->getAuthoredReaction($target, false))->toBe($expected);
    $playback->resume();
    $previous = $tick;
  }
  $playback->cancel();
  expect($playback->getAuthoredReaction($target, false))->toBeNull();
});

it('rejects overlapping windows and unsupported reaction roles', function (string $role, int $secondFrame) {
  $data = battleReactionData();
  $data['tracks'][0]['role'] = $role;
  $data['tracks'][0]['keyframes'][1]['frame'] = $secondFrame;
  expect(fn() => (new EffectTimelineValidator(''))->compile('invalid', $data, forBattle: true))
    ->toThrow(InvalidArgumentException::class);
})->with([['knockout', 8], ['damage', 5]]);
