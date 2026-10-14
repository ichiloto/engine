<?php

use Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession;
use Ichiloto\Engine\Animations\Timelines\LegacyAnimationTimeline;

it('imports text frames without trimming blank rows, ANSI colour or frame order', function () {
  $frames = ["\n  FIRST\n\n", "\033[31mSECOND\033[0m", ''];
  $timeline = LegacyAnimationTimeline::compileTextFrames('authored-entry', $frames, 15);
  $session = new EffectPlaybackSession($timeline);
  expect($timeline->sourceId)->toBe('authored-entry')->and($session->fps)->toBe(15)
    ->and($session->totalFrames)->toBe(3)->and($timeline->defaults['restFrame'])->toBe(2);
  foreach ($frames as $index => $frame) {
    $command = $session->getActiveSegments($index)[0]['drawCommands'][0];
    expect($command['content'] ?? '')->toBe($frame)->and($command['payload']['anchor'])->toBe('screen');
  }
  expect($session->update(3 / 15)->crossedFrames)->toBe([1, 2])->and($session->isCompleted)->toBeTrue();
});

it('refuses invalid legacy text imports through the shared timeline contract', function ($id, $frames, $fps) {
  expect(fn() => LegacyAnimationTimeline::compileTextFrames($id, $frames, $fps))
    ->toThrow(InvalidArgumentException::class);
})->with([
  'empty sequence' => ['entry', [], 10],
  'unordered sequence' => ['entry', [1 => 'A'], 10],
  'non-text frame' => ['entry', [123], 10],
  'invalid identity' => ['../entry', ['A'], 10],
  'invalid rate' => ['entry', ['A'], 0],
  'unbounded frame' => ['entry', [str_repeat('A', 65537)], 10],
]);
