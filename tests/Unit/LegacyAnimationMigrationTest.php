<?php

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationCell;
use Ichiloto\Engine\Animations\AnimationCue;
use Ichiloto\Engine\Animations\AnimationFrame;
use Ichiloto\Engine\Animations\AnimationTargetPosition;
use Ichiloto\Engine\Animations\Field\FieldEffectAnchor;
use Ichiloto\Engine\Animations\Field\FieldEffectSession;
use Ichiloto\Engine\Animations\Timelines\EffectCadence;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Animations\Timelines\LegacyAnimationMigration;
use Ichiloto\Engine\Animations\Timelines\LegacyAnimationTimeline;

function getMigrationDraws(CompiledEffectTimeline $timeline, int $frame, bool $sort = true): array
{
  $draws = [];
  foreach ($timeline->playbackSegments as $segment) {
    if ($segment['startFrame'] > $frame || $segment['endFrame'] < $frame) { continue; }
    foreach ($segment['drawCommands'] as $draw) {
      if (($draw['visible'] ?? true) === false) { continue; }
      unset($draw['trackId']);
      $draw = ['position' => null, 'content' => null, 'assetId' => null, 'color' => null,
        'visible' => true, 'zIndex' => 0, 'blendMode' => null, 'easing' => null, ...$draw];
      ksort($draw);
      ksort($draw['payload']);
      $draws[] = ['layer' => $segment['layer'], 'draw' => $draw];
    }
  }
  if ($sort) { usort($draws, static fn(array $a, array $b): int => json_encode($a) <=> json_encode($b)); }
  return $draws;
}

it('exports colours offsets gaps overlapping flashes tails and sound without changing the source', function () {
  $animation = new Animation(7, 'Synthetic', AnimationTargetPosition::HEAD, maxFrames: 4,
    frames: [new AnimationFrame(1, [new AnimationCell('*', -2, 1, 'red'), new AnimationCell('+', 1, -1, 'blue')]),
      new AnimationFrame(3, [new AnimationCell('X', -2, 1, 'green')])],
    cues: [1 => new AnimationCue('first.wav', 'red', 4), 3 => new AnimationCue('last.wav', 'blue', 4)]);
  $before = $animation->toArray();
  $data = LegacyAnimationMigration::getTimelineData('converted', $animation, EffectCadence::FIXED, 1, 5, fps: 10);
  $converted = (new EffectTimelineLibrary(''))->compile('converted', $data, forBattle: true);
  $original = LegacyAnimationTimeline::compile($animation, 10);
  expect($data['lengthFrames'])->toBe(6)->and($data['restFrame'])->toBe(5)
    ->and($converted->cueSchedule)->toEqual($original->cueSchedule)
    ->and($animation->toArray())->toBe($before);
  for ($frame = 0; $frame <= 6; $frame++) {
    expect(getMigrationDraws($converted, $frame))->toEqual(getMigrationDraws($original, $frame));
  }
});

it('preserves exact fixed consumer duration using an explicitly chosen tick grid', function () {
  $animation = new Animation(2, 'Field', maxFrames: 3,
    frames: [new AnimationFrame(1, [new AnimationCell('A', 0, 0)]), new AnimationFrame(3, [new AnimationCell('B', 0, 0)])],
    cues: [2 => new AnimationCue('middle.wav', 'red', 5)]);
  $data = LegacyAnimationMigration::getTimelineData('field', $animation, EffectCadence::FIXED, 3, 0,
    fps: 25, includeFlash: false);
  $converted = (new EffectTimelineLibrary(''))->compile('field', $data);
  $original = LegacyAnimationTimeline::compile($animation, includeFlash: false);
  expect($data['lengthFrames'])->toBe(9)->and($data['lengthFrames'] / $data['fps'])->toBe(.36)
    ->and($data['restFrame'])->toBe(0)->and($converted->cueSchedule[0]['frame'])->toBe(3);
  for ($frame = 0; $frame < 9; $frame++) {
    expect(getMigrationDraws($converted, $frame))->toEqual(getMigrationDraws($original, intdiv($frame, 3)));
  }
});

it('exports battle consumer pacing without inventing an authored rate', function () {
  $animation = new Animation(2, 'Battle', maxFrames: 3,
    frames: [new AnimationFrame(2, [new AnimationCell('*', 0, 0)])]);
  $data = LegacyAnimationMigration::getTimelineData('battle', $animation, EffectCadence::BATTLE_PHASE, 1, 2);
  $timeline = (new EffectTimelineLibrary(''))->compile('battle', $data, forBattle: true);
  expect($data)->not->toHaveKey('fps')->and($timeline->cadence)->toBe(EffectCadence::BATTLE_PHASE)
    ->and($timeline->defaults['lengthFrames'])->toBe(3);
});

it('preserves painter order when overlapping multi-character cells reverse position between frames', function () {
  $animation = new Animation(8, 'Overlapping', maxFrames: 2,
    frames: [new AnimationFrame(1, [new AnimationCell('AB', 0, 0), new AnimationCell('X', 1, 0)]),
      new AnimationFrame(2, [new AnimationCell('Y', 1, 0), new AnimationCell('CD', 0, 0)])]);
  $data = LegacyAnimationMigration::getTimelineData('overlapping', $animation, EffectCadence::FIXED, 1, 1, fps: 10);
  $converted = (new EffectTimelineLibrary(''))->compile('overlapping', $data, forBattle: true);
  $original = LegacyAnimationTimeline::compile($animation, 10);
  foreach ([0, 1] as $frame) {
    expect(getMigrationDraws($converted, $frame, sort: false))
      ->toEqual(getMigrationDraws($original, $frame, sort: false));
  }
});

it('uses moving tracks rather than exhausting the composition limit across distinct positions', function () {
  $animation = new Animation(9, 'Moving', maxFrames: 40);
  for ($frame = 1; $frame <= 40; $frame++) { $animation->setCell($frame, $frame, 0, '*'); }
  $data = LegacyAnimationMigration::getTimelineData('moving', $animation, EffectCadence::FIXED, 1, 39, fps: 10);
  $converted = (new EffectTimelineLibrary(''))->compile('moving', $data, forBattle: true);
  $original = LegacyAnimationTimeline::compile($animation, 10);
  expect($data['tracks'])->toHaveCount(1);
  for ($frame = 0; $frame < 40; $frame++) {
    expect(getMigrationDraws($converted, $frame))->toEqual(getMigrationDraws($original, $frame));
  }
});

it('preserves the authored attachment for every legacy target position', function (AnimationTargetPosition $position) {
  $animation = new Animation(10, 'Attachment', $position, maxFrames: 2,
    frames: [new AnimationFrame(1, [new AnimationCell('*', -1, 2)])],
    cues: [1 => new AnimationCue(flashColor: 'red', flashDurationFrames: 2)]);
  $data = LegacyAnimationMigration::getTimelineData('attachment', $animation, EffectCadence::FIXED, 1, 0, fps: 10);
  $converted = (new EffectTimelineLibrary(''))->compile('attachment', $data, forBattle: true);
  $original = LegacyAnimationTimeline::compile($animation, 10);
  foreach ([0, 1] as $frame) {
    expect(getMigrationDraws($converted, $frame))->toEqual(getMigrationDraws($original, $frame));
  }
})->with(AnimationTargetPosition::cases());

it('retains a complete reduced-motion rest frame while sounds remain exactly once on the elapsed clock', function () {
  $animation = new Animation(11, 'Rest', maxFrames: 3,
    frames: [new AnimationFrame(1, [new AnimationCell('A', 0, 0)]),
      new AnimationFrame(3, [new AnimationCell('R', 0, 0)])],
    cues: [1 => new AnimationCue('entry.wav'), 2 => new AnimationCue('middle.wav'),
      3 => new AnimationCue('last.wav')]);
  $data = LegacyAnimationMigration::getTimelineData('rest', $animation, EffectCadence::FIXED, 3, 2,
    fps: 25, includeFlash: false);
  $timeline = (new EffectTimelineLibrary(''))->compile('rest', $data);
  $session = new FieldEffectSession('rest', FieldEffectAnchor::fromArray(['cell' => ['x' => 4, 'y' => 5]]), $timeline);
  for ($inspection = 0; $inspection < 3; $inspection++) {
    expect($session->getActiveSegments(reducedMotion: true)[0]['drawCommands'][0]['content'])->toBe('R');
  }
  expect($session->playback->currentFrame)->toBe(0)
    ->and($session->update(.001, reducedMotion: true)->crossedCues[0]['payload']['sound'])->toBe('entry.wav');
  $session->playback->pause();
  expect($session->update(1, reducedMotion: true)->crossedCues)->toBe([]);
  $session->playback->resume();
  expect(array_column(array_column($session->update(.359, reducedMotion: true)->crossedCues, 'payload'), 'sound'))
    ->toBe(['middle.wav', 'last.wav'])
    ->and($session->playback->isCompleted)->toBeTrue()
    ->and($session->getActiveSegments(reducedMotion: true))->toBe([])
    ->and($session->update(1, reducedMotion: true)->crossedCues)->toBe([]);
});

it('retains silent blank duration and sound-only cues without adding visible content', function (bool $sound) {
  $animation = new Animation(3, 'Empty', maxFrames: 4,
    cues: $sound ? [2 => new AnimationCue('cue.wav')] : []);
  $data = LegacyAnimationMigration::getTimelineData('empty', $animation, EffectCadence::FIXED, 2, 0, fps: 20);
  $timeline = (new EffectTimelineLibrary(''))->compile('empty', $data);
  expect($timeline->defaults['lengthFrames'])->toBe(8)->and($timeline->cueSchedule)->toHaveCount($sound ? 1 : 0);
  for ($frame = 0; $frame < 8; $frame++) { expect(getMigrationDraws($timeline, $frame))->toBe([]); }
})->with([false, true]);

it('refuses ambiguous timing invalid rest and unsupported ranges instead of dropping data', function () {
  $animation = new Animation(4, 'Invalid', maxFrames: 4);
  foreach ([[EffectCadence::FIXED, 1, 0, null], [EffectCadence::BATTLE_PHASE, 1, 0, 10],
    [EffectCadence::FIXED, 0, 0, 10], [EffectCadence::FIXED, 1, 4, 10],
    [EffectCadence::FIXED, 100000, 0, 10], [EffectCadence::FIXED, 1, 0, 121]] as $arguments) {
    expect(fn() => LegacyAnimationMigration::getTimelineData('invalid', $animation, ...$arguments))
      ->toThrow(InvalidArgumentException::class);
  }
  $animation->setCell(1, 20000, 0, '*');
  expect(fn() => LegacyAnimationMigration::getTimelineData('invalid', $animation, EffectCadence::FIXED, 1, 0, fps: 10))
    ->toThrow(InvalidArgumentException::class);
});

it('refuses oversized source composition without truncating or mutating it', function () {
  $animation = new Animation(4, 'Large');
  for ($x = 0; $x < 33; $x++) { $animation->setCell(1, $x, 0, '*'); }
  $before = $animation->toArray();
  expect(fn() => LegacyAnimationMigration::getTimelineData('large', $animation, EffectCadence::FIXED, 1, 0, fps: 10))
    ->toThrow(InvalidArgumentException::class, '32-track limit')
    ->and($animation->toArray())->toBe($before);
});
