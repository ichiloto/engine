<?php

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationCell;
use Ichiloto\Engine\Animations\AnimationCue;
use Ichiloto\Engine\Animations\AnimationFrame;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\LegacyAnimationTimeline;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneCompiler;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;

function commandPlaybackFixture(?CompiledEffectTimeline $source = null, ?CompiledEffectTimeline $target = null,
  ?callable $resolve = null, ?callable $present = null): BattleCommandPlayback
{
  $actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  return new BattleCommandPlayback(new BattleCommandTimeline(
    new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), $source, $target),
    $actor, [$actor], BattlePoseRole::ATTACK, $resolve ?? static fn() => null, $present ?? static fn() => null);
}

it('resolves once between target effects and reaction even when updates cross every stage', function () {
  $events = [];
  $playback = commandPlaybackFixture(resolve: function () use (&$events) { $events[] = 'impact'; },
    present: function (array $cue) use (&$events) {
      if ($cue['type'] === 'commandPhase') { $events[] = $cue['payload']['phase']; }
    });
  $playback->begin();
  $playback->begin();
  $playback->update(5);
  $playback->update(5);
  expect($events)->toBe(['advance', 'announce', 'source', 'target', 'impact', 'reaction', 'return', 'finish'])
    ->and($playback->isCompleted)->toBeTrue()->and($playback->getAdvanceFraction())->toBe(0.0);
});

it('preserves authored effect cadence and target cue impact instead of the source cue', function () {
  $effect = new CompiledEffectTimeline('test', '', fps: 5, playbackSegments: [[
    'startFrame' => 0, 'endFrame' => 1, 'layer' => 'image',
    'drawCommands' => [['trackId' => 'spark', 'assetId' => 'spark.png', 'payload' => []]],
  ]], cueSchedule: [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 2, 'payload' => []]],
    defaults: ['lengthFrames' => 4]);
  $plan = commandPlaybackFixture($effect, $effect)->plan;
  expect($plan->phases['source']['length'])->toBe(96)->and($plan->phases['target']['length'])->toBe(96)
    ->and($plan->timeline->playbackSegments[0]['endFrame'])->toBe($plan->phases['source']['start'] + 47)
    ->and(array_find($plan->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame'])
    ->toBe($plan->phases['target']['start'] + 48);
});

it('keeps adjacent effect frames exclusive at every supported cadence', function (int $fps) {
  $effect = new CompiledEffectTimeline('cadence', '', fps: $fps, playbackSegments: array_map(
    static fn(int $frame): array => ['startFrame' => $frame, 'endFrame' => $frame, 'layer' => 'image',
      'drawCommands' => [['trackId' => 'art', 'payload' => ['sourceFrame' => $frame]]]], range(0, 6)),
    defaults: ['lengthFrames' => 7, 'restFrame' => 3]);
  $playback = commandPlaybackFixture($effect, $effect);
  foreach (['source', 'target'] as $phase) {
    $start = $playback->plan->phases[$phase]['start'];
    $length = $playback->plan->phases[$phase]['length'];
    for ($tick = 0; $tick < $length; $tick++) {
      $segments = $playback->session->getActiveSegments($start + $tick);
      expect($segments)->toHaveCount(1)
        ->and($segments[0]['drawCommands'][0]['payload']['sourceFrame'])
        ->toBe(min(6, intdiv($tick * $fps, BattleCommandTimeline::FPS)));
    }
    $rest = $playback->session->getActiveSegments($playback->plan->restFrames[$phase]);
    expect($rest)->toHaveCount(1)->and($rest[0]['drawCommands'][0]['payload']['sourceFrame'])->toBe(3);
  }
})->with(range(1, 120));

it('never fires non-divisor cadence impact or sound cues before their authored boundary', function (bool $explicitFrame) {
  $effect = new CompiledEffectTimeline('cadence-cues', '', fps: 48,
    cueSchedule: [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 1, 'payload' => []],
      ['id' => 'sound', 'type' => 'playSound', 'frame' => 1, 'payload' => []]],
    defaults: ['lengthFrames' => 7, 'effectTiming' => $explicitFrame
      ? ['mode' => 'frame', 'frame' => 1] : ['mode' => 'cue', 'cueId' => 'hit']]);
  $hits = 0;
  $cues = [];
  $playback = commandPlaybackFixture(target: $effect, resolve: function () use (&$hits) { $hits++; },
    present: function (array $cue) use (&$cues) {
      if ($cue['type'] === 'playSound') { $cues[] = $cue; }
    });
  $start = $playback->plan->phases['target']['start'];
  $playback->update(($start + 2) / BattleCommandTimeline::FPS);
  expect($hits)->toBe(0)->and($cues)->toBeEmpty();
  $playback->update(1 / BattleCommandTimeline::FPS);
  expect($hits)->toBe(1)->and($cues)->toHaveCount(1)->and($cues[0]['frame'])->toBe($start + 3);
  $playback->update(10);
  expect($hits)->toBe(1)->and($cues)->toHaveCount(1)->and($playback->isCompleted)->toBeTrue();
})->with([false, true]);

it('honors authored speed for exclusive effect frames and preserves skipped rest art', function (int $fps, float $speed) {
  $effect = new CompiledEffectTimeline('speed', '', fps: $fps, playbackSegments: array_map(
    static fn(int $frame): array => ['startFrame' => $frame, 'endFrame' => $frame, 'layer' => 'image',
      'drawCommands' => [['trackId' => 'art', 'payload' => ['sourceFrame' => $frame]]]], range(0, 6)),
    defaults: ['lengthFrames' => 7, 'restFrame' => 3, 'playback' => ['defaultSpeed' => $speed, 'loop' => true]]);
  $playback = commandPlaybackFixture($effect, $effect);
  $previous = 0;
  foreach (['source', 'target'] as $phase) {
    $start = $playback->plan->phases[$phase]['start'];
    $length = (int)ceil(7 / ($fps * $speed) * BattleCommandTimeline::FPS);
    expect($playback->plan->phases[$phase]['length'])->toBe($length);
    for ($tick = 0; $tick < $length; $tick++) {
      $at = $start + $tick;
      $playback->update(($at - $previous) / BattleCommandTimeline::FPS);
      $previous = $at;
      $normal = $playback->getActiveSegments();
      $frame = (int)floor($tick * $fps * $speed / BattleCommandTimeline::FPS + 1e-12);
      expect($playback->phase)->toBe($phase)->and($normal)->toHaveCount(1)
        ->and($normal[0]['drawCommands'][0]['payload']['sourceFrame'])->toBe($frame);
      $calm = $playback->getActiveSegments(true);
      expect($calm)->toHaveCount(1)->and($calm[0]['drawCommands'][0]['payload']['sourceFrame'])->toBe(3)
        ->and($calm[0]['drawCommands'][0]['trackId'])->toBe($phase . '-art')
        ->and($calm[0]['drawCommands'][0]['payload']['anchor'])->toBe($phase === 'source' ? 'caster' : 'target')
        ->and($playback->session->currentFrame)->toBe($at);
    }
  }
  $playback->update(10);
  expect($playback->isCompleted)->toBeTrue()->and($playback->getActiveSegments(true))->toBeEmpty();
})->with([[48, .5], [48, 1.25], [120, 4.0], [7, 1.4], [24, 1.0]]);

it('scales impact and cue durations together without letting source cues resolve gameplay', function (bool $explicitFrame) {
  $effect = new CompiledEffectTimeline('speed-cues', '', fps: 48, cueSchedule: [
    ['id' => 'hit', 'type' => 'applyEffect', 'frame' => 1, 'payload' => []],
    ['id' => 'sound', 'type' => 'playSound', 'frame' => 1, 'payload' => []],
    ['id' => 'flash', 'type' => 'flash', 'frame' => 1, 'payload' => ['durationFrames' => 1]],
    ['id' => 'shake', 'type' => 'shake', 'frame' => 3, 'payload' => ['duration' => 1]],
  ], defaults: ['lengthFrames' => 7, 'playback' => ['defaultSpeed' => 2.0],
    'effectTiming' => $explicitFrame ? ['mode' => 'frame', 'frame' => 1] : ['mode' => 'cue', 'cueId' => 'hit']]);
  $hits = 0;
  $cues = [];
  $playback = commandPlaybackFixture($effect, $effect, resolve: function () use (&$hits) { $hits++; },
    present: function (array $cue) use (&$cues) { if ($cue['type'] === 'playSound') { $cues[] = $cue; } });
  $start = $playback->plan->phases['target']['start'];
  $playback->update(($start + 1) / BattleCommandTimeline::FPS);
  expect($hits)->toBe(0)->and($cues)->toHaveCount(1);
  $playback->update(1 / BattleCommandTimeline::FPS);
  expect($hits)->toBe(1)->and($cues)->toHaveCount(2)->and($cues[1]['frame'])->toBe($start + 2);
  $segments = array_values(array_filter($playback->plan->timeline->playbackSegments,
    static fn(array $segment): bool => $segment['startFrame'] >= $start));
  expect(array_column($segments, 'startFrame'))->toBe([$start + 2, $start + 4])
    ->and(array_column($segments, 'endFrame'))->toBe([$start + 2, $start + 4]);
  $playback->pause();
  $playback->update(5);
  expect($hits)->toBe(1)->and($cues)->toHaveCount(2);
  $playback->resume();
  $playback->update(10);
  expect($hits)->toBe(1)->and($cues)->toHaveCount(2)->and($playback->isCompleted)->toBeTrue();
})->with([false, true]);

it('samples terminal fallback at its own authored speed while target graphics finish earlier', function () {
  $image = new CompiledEffectTimeline('fast-image', '', fps: 12, playbackSegments: [[
    'startFrame' => 0, 'endFrame' => 1, 'layer' => 'image', 'drawCommands' => [],
  ]], defaults: ['lengthFrames' => 2, 'playback' => ['defaultSpeed' => 2.0]]);
  $terminal = new CompiledEffectTimeline('slow-terminal', '', fps: 12, playbackSegments: array_map(
    static fn(int $frame): array => ['startFrame' => $frame, 'endFrame' => $frame, 'layer' => 'glyph',
      'drawCommands' => [['trackId' => 'glyph', 'content' => strval($frame)]]], range(0, 3)),
    defaults: ['lengthFrames' => 4, 'restFrame' => 2, 'playback' => ['defaultSpeed' => .5]]);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $image, terminalTarget: $terminal);
  $actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  $hits = 0;
  $playback = new BattleCommandPlayback($plan, $actor, [$actor], BattlePoseRole::ATTACK,
    function () use (&$hits) { $hits++; }, static fn() => null);
  expect($plan->phases['target']['length'])->toBe(80);
  $previous = 0;
  foreach ([0 => '0', 19 => '0', 20 => '1', 59 => '2', 60 => '3', 79 => '3'] as $tick => $glyph) {
    $at = $plan->phases['target']['start'] + $tick;
    $playback->update(($at - $previous) / BattleCommandTimeline::FPS);
    $previous = $at;
    $normal = array_values(array_filter($playback->getActiveSegments(false, true),
      static fn(array $segment): bool => $segment['layer'] === 'glyph'));
    $calm = array_values(array_filter($playback->getActiveSegments(true, true),
      static fn(array $segment): bool => $segment['layer'] === 'glyph'));
    expect($normal)->toHaveCount(1)->and($normal[0]['drawCommands'][0]['content'])->toBe($glyph)
      ->and($calm)->toHaveCount(1)->and($calm[0]['drawCommands'][0]['content'])->toBe('2')->and($hits)->toBe(0);
  }
  $playback->update(10);
  expect($hits)->toBe(1)->and($playback->isCompleted)->toBeTrue();
});

it('dispatches every fast authored cue once even when multiple frames collapse onto one command tick', function () {
  $effect = new CompiledEffectTimeline('fast-cues', '', fps: 120,
    cueSchedule: array_map(static fn(int $frame): array => ['id' => 'sound-' . $frame,
      'type' => 'playSound', 'frame' => $frame, 'payload' => []], range(0, 6)),
    defaults: ['lengthFrames' => 7, 'playback' => ['defaultSpeed' => 10.0],
      'effectTiming' => ['mode' => 'frame', 'frame' => 3]]);
  $hits = 0;
  $cues = [];
  $playback = commandPlaybackFixture($effect, $effect, resolve: function () use (&$hits) { $hits++; },
    present: function (array $cue) use (&$cues) { if ($cue['type'] === 'playSound') { $cues[] = $cue['id']; } });
  expect($playback->plan->phases['source']['length'])->toBe(1)
    ->and($playback->plan->phases['target']['length'])->toBe(1);
  $playback->update(10);
  $playback->update(10);
  expect($cues)->toBe([...array_map(static fn(int $frame): string => 'sound-' . $frame, range(0, 6)),
    ...array_map(static fn(int $frame): string => 'sound-' . $frame, range(0, 6))])
    ->and($hits)->toBe(1)->and($playback->isCompleted)->toBeTrue();
});

it('rejects an invalid authored source or target speed before gameplay starts', function (bool $source) {
  $effect = new CompiledEffectTimeline('invalid-speed', '', defaults: ['playback' => ['defaultSpeed' => INF]]);
  expect(fn() => commandPlaybackFixture($source ? $effect : null, $source ? null : $effect))
    ->toThrow(InvalidArgumentException::class, 'Effect playback speed must be finite.');
})->with([false, true]);

it('preserves real authored summon speed without scaling its independently timed title and transitions', function () {
  $definition = SummonCutsceneDefinition::fromArrays([
    'id' => 'paced-summon', 'name' => 'Paced summon', 'playback' => ['defaultSpeed' => 2.0],
    'transitionIn' => ['type' => 'fadeToBlack', 'durationMs' => 600],
    'transitionOut' => ['type' => 'fadeFromBlack', 'durationMs' => 300],
    'effectTiming' => ['mode' => 'frame', 'frame' => 2],
  ], ['fps' => 48, 'lengthFrames' => 7, 'tracks' => [], 'cues' => []]);
  $effect = new SummonCutsceneCompiler()->compile($definition);
  $plan = commandPlaybackFixture(target: $effect)->plan;
  expect($plan->summon)->toBe($effect)->and($plan->phases['target']['length'])->toBe(9)
    ->and($plan->phases['summon-in']['length'])->toBe(72)
    ->and($plan->phases['summon-title']['length'])->toBe(96)
    ->and($plan->phases['summon-out']['length'])->toBe(36)
    ->and(array_find($plan->timeline->cueSchedule, fn(array $cue): bool => $cue['type'] === 'commandImpact')['frame'])
    ->toBe($plan->phases['target']['start'] + 3);
});

it('retimes cue durations by their absolute boundaries rather than adding rounded durations', function () {
  $effect = new CompiledEffectTimeline('cadence-cue-duration', '', fps: 48, cueSchedule: [
    ['id' => 'flash', 'type' => 'flash', 'frame' => 1, 'payload' => ['durationFrames' => 1]],
    ['id' => 'shake', 'type' => 'shake', 'frame' => 3, 'payload' => ['duration' => 1]],
  ], defaults: ['lengthFrames' => 7]);
  $plan = commandPlaybackFixture(target: $effect)->plan;
  $start = $plan->phases['target']['start'];
  expect(array_column($plan->timeline->playbackSegments, 'startFrame'))->toBe([$start + 3, $start + 8])
    ->and(array_column($plan->timeline->playbackSegments, 'endFrame'))->toBe([$start + 4, $start + 9]);
  $flash = array_find($plan->timeline->cueSchedule, static fn(array $cue): bool => $cue['id'] === 'flash');
  expect($flash['payload']['durationFrames'])->toBe(2);
});

it('finishes longer terminal target playback without duplicating resolution or changing explicit impact cues', function (bool $reduced, bool $explicitImpact) {
  $target = new CompiledEffectTimeline('short-image', '', fps: 12, playbackSegments: [[
    'startFrame' => 0, 'endFrame' => 7, 'layer' => 'image',
    'drawCommands' => [['trackId' => 'art', 'assetId' => 'art.png', 'payload' => []]],
  ]], cueSchedule: $explicitImpact ? [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 3, 'payload' => []]] : [],
    defaults: ['lengthFrames' => 8, 'restFrame' => 3]);
  $terminal = LegacyAnimationTimeline::compile(new Animation(7, 'Long terminal treatment', maxFrames: 16,
    frames: [new AnimationFrame(1, [new AnimationCell('/', 0, 0)]),
      new AnimationFrame(9, [new AnimationCell('\\', 0, 0)]),
      new AnimationFrame(16, [new AnimationCell('\\', 0, 0)])]), 10);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $target, terminalTarget: $terminal);
  $actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  $resolved = 0;
  $playback = new BattleCommandPlayback($plan, $actor, [$actor], BattlePoseRole::ATTACK,
    function () use (&$resolved) { $resolved++; }, static fn() => null);
  expect($plan->phases['target']['length'])->toBe(192)
    ->and(array_find($plan->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame'])
    ->toBe($plan->phases['target']['start'] + ($explicitImpact ? 30 : 192));
  $previous = 0;
  foreach ([0 => '/', 8 => '\\', 15 => '\\'] as $frame => $symbol) {
    $wanted = $plan->phases['target']['start'] + $frame * 12;
    $playback->update(($wanted - $previous) / 120);
    $previous = $wanted;
    $glyphs = array_values(array_filter($playback->getActiveSegments($reduced, true),
      static fn(array $segment): bool => $segment['layer'] === 'glyph'));
    expect($playback->phase)->toBe('target')->and($glyphs)->toHaveCount(1)
      ->and($glyphs[0]['drawCommands'][0]['content'])->toBe($reduced ? '\\' : $symbol)
      ->and(array_column($playback->getActiveSegments($reduced), 'layer'))->not->toContain('glyph')
      ->and($resolved)->toBe($explicitImpact && $frame > 0 ? 1 : 0);
  }
  $playback->update(10);
  $playback->update(10);
  expect($resolved)->toBe(1)->and($playback->isCompleted)->toBeTrue();
})->with([false, true])->with([false, true]);

it('does not extend target pacing for a superseded legacy terminal treatment', function () {
  $target = new CompiledEffectTimeline('shared-glyph', '', fps: 12, playbackSegments: [[
    'startFrame' => 0, 'endFrame' => 7, 'layer' => 'glyph',
    'drawCommands' => [['trackId' => 'shared', 'content' => '*', 'payload' => []]],
  ]], defaults: ['lengthFrames' => 8]);
  $terminal = LegacyAnimationTimeline::compile(new Animation(7, 'Unused legacy', maxFrames: 16));
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $target, terminalTarget: $terminal);
  expect($plan->terminalTarget)->toBeNull()->and($plan->phases['target']['length'])->toBe(80);
});

it('honors explicit summon frame and end policies without letting applyEffect override them', function (array $timing, int $offset) {
  $effect = new CompiledEffectTimeline('summon', '', fps: 10,
    cueSchedule: [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 0, 'payload' => []]],
    defaults: ['lengthFrames' => 4, 'effectTiming' => $timing]);
  $plan = commandPlaybackFixture(target: $effect)->plan;
  expect(array_find($plan->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame'])
    ->toBe($plan->phases['target']['start'] + $offset);
})->with([[['mode' => 'frame', 'frame' => 2], 24], [['mode' => 'end'], 48], [['mode' => 'cue', 'cueId' => 'hit'], 0]]);

it('pauses the playhead and abandons pre-impact commands without executing gameplay', function () {
  $resolved = 0;
  $playback = commandPlaybackFixture(resolve: function () use (&$resolved) { $resolved++; });
  $playback->begin();
  $playback->update(.05);
  $frame = $playback->session->currentFrame;
  $playback->pause();
  $playback->update(10);
  expect($playback->session->currentFrame)->toBe($frame)->and($resolved)->toBe(0);
  $playback->resume();
  $playback->update(.05);
  expect($playback->session->currentFrame)->toBeGreaterThan($frame);
  $playback->cancel();
  $playback->update(10);
  expect($resolved)->toBe(0)->and($playback->getAdvanceFraction())->toBe(0.0);
});

it('does not lose gameplay resolution or later cues when a presentation callback fails', function () {
  $resolved = 0;
  $events = [];
  $playback = commandPlaybackFixture(resolve: function () use (&$resolved) { $resolved++; },
    present: function (array $cue) use (&$events) {
      if ($cue['type'] !== 'commandPhase') { return; }
      $events[] = $cue['payload']['phase'];
      if ($cue['payload']['phase'] === 'source') { throw new RuntimeException('Image unavailable'); }
    });
  $playback->update(5);
  expect($resolved)->toBe(1)->and($events)->toContain('reaction', 'return', 'finish')
    ->and($playback->presentationFailure?->getMessage())->toBe('Image unavailable');
});

it('propagates gameplay failures without retrying the outcome', function () {
  $resolved = 0;
  $playback = commandPlaybackFixture(resolve: function () use (&$resolved) {
    $resolved++; throw new RuntimeException('Gameplay failed');
  });
  expect(fn() => $playback->update(5))->toThrow(RuntimeException::class, 'Gameplay failed');
  $playback->update(5);
  expect($resolved)->toBe(1);
});

it('imports legacy cells, blank frames, sound and flash duration into the shared session', function () {
  $animation = new Animation(1, 'Spark', maxFrames: 2,
    frames: [new AnimationFrame(1, [new AnimationCell('*', -1, 0, 'red')])],
    cues: [1 => new AnimationCue(soundEffect: 'hit', flashColor: 'red', flashDurationFrames: 4)]);
  $timeline = LegacyAnimationTimeline::compile($animation, 10);
  expect($timeline->fps)->toBe(10)->and($timeline->defaults['lengthFrames'])->toBe(4)
    ->and($timeline->playbackSegments[0]['drawCommands'][0]['position'])->toBe(['x' => -1, 'y' => 0])
    ->and($timeline->cueSchedule[0]['payload']['sound'])->toBe('hit');
  expect(LegacyAnimationTimeline::compile(new Animation(2, 'Empty'))->defaults['lengthFrames'])->toBe(1);
});

it('rejects invalid pacing before starting a battle command', function () {
  expect(fn() => new BattleCommandTimeline(new BattleTurnTimings(INF, 0, 0, 0, 0, 0, 0)))
    ->toThrow(InvalidArgumentException::class);
});

it('crosses exact high-rate clock boundaries without delaying frame cues', function () {
  $timeline = new CompiledEffectTimeline('clock', '', fps: 120,
    cueSchedule: [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 96]], defaults: ['lengthFrames' => 120]);
  $session = new \Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession($timeline);
  $session->update(.2);
  expect($session->currentFrame)->toBe(24);
  $session->update(.4);
  expect($session->currentFrame)->toBe(72);
  expect(array_column($session->update(.2)->crossedCues, 'id'))->toBe(['hit'])
    ->and($session->currentFrame)->toBe(96);
  $session->update(.2);
  expect($session->isCompleted)->toBeTrue();
});
