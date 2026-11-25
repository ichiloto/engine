<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\Timelines\{EffectPlaybackTiming, EffectPresentation, EffectTimelineValidator};
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCommandPlayback, BattleCommandTimeline, BattlePoseRole,
  TerminalBattleContacts, TerminalBattleEffects, TerminalBattleLaneSnapshot};
use Ichiloto\Engine\Entities\{Character, Stats};
use Ichiloto\Engine\IO\Console\TerminalText;

function terminalContactFixture(): array
{
  return [
    'fps' => 24, 'lengthFrames' => 9, 'restFrame' => 6, 'targetCaptureFrame' => 1,
    'effectTiming' => ['mode' => 'frame', 'frame' => 3],
    'terminalTargetContacts' => ['intervalFrames' => 2, 'tracks' => ['trail', 'burst']],
    'tracks' => [
      ['id' => 'trail', 'type' => 'glyph-lane', 'facing' => 'west',
        'keyframes' => [
          ['frame' => 2, 'duration' => 2, 'payload' => ['head' => ['H'], 'shaft' => ['-']]],
        ]],
      ['id' => 'burst', 'type' => 'glyph', 'anchor' => 'target', 'presentation' => 'terminal',
        'keyframes' => [
          ['frame' => 3, 'duration' => 3, 'content' => "^\n*", 'position' => ['x' => 0, 'y' => -1]],
          ['frame' => 6, 'duration' => 2, 'content' => '!', 'position' => ['x' => 0, 'y' => -1]],
        ]],
    ],
  ];
}

it('rejects malformed Terminal contact authoring before battle playback', function (Closure $change) {
  $data = terminalContactFixture();
  $change($data);
  expect(fn() => (new EffectTimelineValidator(''))->compile('terminal-contact', $data, true, EffectPresentation::TERMINAL))
    ->toThrow(InvalidArgumentException::class);
})->with([
  [fn(array &$data) => $data['terminalTargetContacts']['intervalFrames'] = 0],
  [fn(array &$data) => $data['terminalTargetContacts']['tracks'] = ['absent']],
  [fn(array &$data) => $data['terminalTargetContacts']['tracks'] = ['burst', 'burst']],
  [fn(array &$data) => $data['tracks'][1]['presentation'] = 'all'],
  [fn(array &$data) => $data['tracks'][1]['anchor'] = 'screen'],
  [fn(array &$data) => $data['tracks'][1]['keyframes'][0]['payload'] = ['legacyPosition' => 'head']],
  [fn(array &$data) => $data['tracks'][1]['keyframes'][0]['payload'] = ['clearBeforeDraw' => true]],
  [function(array &$data) {
    $data['terminalTargetContacts']['tracks'] = ['burst'];
    $data['tracks'] = [$data['tracks'][1]];
    $data['targetCaptureFrame'] = 4;
  }],
]);

it('keeps a zero-reach lane hidden even when reduced motion selects a visible rest sample', function () {
  $source = terminalContactFixture();
  $source['tracks'][0]['keyframes'] = [
    ['frame' => 2, 'payload' => ['head' => ['H'], 'shaft' => ['-'], 'reach' => 0]],
    ['frame' => 3, 'payload' => ['head' => ['H'], 'shaft' => ['-'], 'reach' => 1]],
  ];
  $compiled = (new EffectTimelineValidator(''))->compile('terminal-contact', $source, true, EffectPresentation::TERMINAL);
  $contacts = new TerminalBattleContacts($compiled, EffectPlaybackTiming::createForBattlePhase($compiled, .1));
  expect($contacts->sample(10, [42 => 0], false))->toBe([])
    ->and($contacts->sample(10, [42 => 0], true))->toBe([])
    ->and(array_column($contacts->sample(15, [42 => 0], true), 'layer'))->toContain('glyph-lane');
});

it('captures independent ground points once, holds reduced-motion art and resolves the command once', function (bool $companion) {
  $source = terminalContactFixture();
  $validator = new EffectTimelineValidator('');
  $terminal = $validator->compile('terminal-contact', $source, true, EffectPresentation::TERMINAL);
  $graphical = $companion
    ? $validator->compile('graphic-control', [
      'fps' => 24, 'lengthFrames' => 9,
      'effectTiming' => ['mode' => 'frame', 'frame' => 3],
      'tracks' => [['id' => 'control', 'type' => 'text', 'presentation' => 'graphical',
        'keyframes' => [['frame' => 3, 'duration' => 2, 'content' => 'Graphic']]]],
    ], true)
    : null;
  $timings = new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1);
  $plan = new BattleCommandTimeline($timings, target: $graphical ?? $terminal, terminalTarget: $companion ? $terminal : null,
    targetCount: 3);
  unset($source['terminalTargetContacts']);
  $ordinary = $validator->compile('ordinary', $source, true, EffectPresentation::TERMINAL);
  $baseline = new BattleCommandTimeline($timings, target: $graphical ?? $ordinary, terminalTarget: $companion ? $ordinary : null, targetCount: 3);
  expect($plan->phases['target']['length'])->toBe(60)->and($baseline->phases['target']['length'])->toBe(45)
    ->and($plan->impactFrame)->toBe($baseline->impactFrame);

  $actor = new Character('Actor', 1, new Stats());
  $far = new Character('Same name', 1, new Stats());
  $near = new Character('Same name', 1, new Stats());
  $mid = new Character('Same name', 1, new Stats());
  $resolutions = 0;
  $playback = new BattleCommandPlayback($plan, $actor, [$far, $near, $mid], BattlePoseRole::ATTACK,
    function () use (&$resolutions) { $resolutions++; }, static fn() => null);
  $captureTicks = [];
  $grounds = [spl_object_id($far) => ['x' => 28, 'y' => 8], spl_object_id($near) => ['x' => 66, 'y' => 16],
    spl_object_id($mid) => ['x' => 58, 'y' => 12]];
  $centres = array_map(static fn(array $point): array => ['x' => 50, 'y' => $point['y'] - 4], $grounds);
  $playback->bindTerminalLaneCapture(function (BattleCommandPlayback $sample) use (&$captureTicks, $grounds, $centres) {
    $captureTicks[] = $sample->session->currentFrame;
    return new TerminalBattleLaneSnapshot(['x' => 80, 'y' => 4], $centres,
      [['x' => 54, 'y' => 10, 'width' => 2, 'height' => 7]], true, ['x' => 90, 'y' => 20], $grounds);
  });
  $renderer = new TerminalBattleEffects(width: 110, height: 32);
  $render = fn(bool $reduced = false): array => $renderer->compose($playback,
    static fn() => throw new RuntimeException('Contact drawing should never request live geometry.'), $reduced)['draws'];
  $contains = static fn(array $draws, string $text, array $at): bool => array_any($draws,
    static fn(array $draw): bool => TerminalText::stripAnsi($draw['text']) === $text && $draw['x'] === $at['x'] && $draw['y'] === $at['y']);
  $move = function (int $authored) use ($playback, $plan): void {
    $tick = $plan->phases['target']['start'] + $authored * 5;
    $playback->update(($tick - $playback->session->currentFrame) / BattleCommandTimeline::FPS);
  };

  $playback->begin();
  $move(1);
  expect($render())->toBeEmpty()->and($resolutions)->toBe(0)
    ->and($captureTicks)->toBe([$plan->phases['target']['start'] + 5]);
  $move(3);
  $first = $render();
  expect($contains($first, '^', ['x' => 66, 'y' => 15]))->toBeTrue()
    ->and($contains($first, '*', ['x' => 66, 'y' => 16]))->toBeTrue()
    ->and($contains($first, '^', ['x' => 58, 'y' => 11]))->toBeFalse()
    ->and($contains($render(true), '!', ['x' => 66, 'y' => 15]))->toBeTrue()
    ->and($resolutions)->toBe(1);
  $lane = array_find($first, static fn(array $draw): bool => str_contains($draw['text'], 'H'));
  expect($lane['x'])->toBe(66)->and($lane['y'])->toBe(16);
  expect(array_column($playback->getActiveSegments(), 'presentation'))->not->toContain('terminal');
  $move(5);
  expect($contains($render(), '^', ['x' => 58, 'y' => 11]))->toBeTrue()
    ->and($contains($render(), '^', ['x' => 28, 'y' => 7]))->toBeFalse();
  $move(7);
  expect($contains($render(), '^', ['x' => 28, 'y' => 7]))->toBeTrue();
  $move(11);
  expect($contains($render(), '!', ['x' => 28, 'y' => 7]))->toBeTrue()
    ->and($resolutions)->toBe(1)->and($captureTicks)->toHaveCount(1)
    ->and($playback->presentationFailure)->toBeNull();
  $playback->cancel();
  expect($render())->toBeEmpty()->and($playback->getCapturedTerminalLane())->toBeNull();
})->with([false, true]);
