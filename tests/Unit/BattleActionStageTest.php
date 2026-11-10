<?php

use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineValidator;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStagePresentation;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

function makeBattleActionStageSource(string $asset): array
{
  return ['fps' => 20, 'lengthFrames' => 8, 'restFrame' => 3,
    'stage' => ['canvas' => ['width' => 320, 'height' => 180], 'startFrame' => 1, 'restoreFrame' => 6,
      'camera' => [['id' => 'wide', 'frame' => 0, 'focus' => ['x' => 160, 'y' => 90], 'zoom' => 1]],
      'covers' => [['id' => 'clear', 'frame' => 0, 'color' => 'black', 'opacity' => 0],
        ['id' => 'entry', 'frame' => 1, 'color' => 'black', 'opacity' => 1],
        ['id' => 'restore', 'frame' => 6, 'color' => 'white', 'opacity' => 1],
        ['id' => 'exit', 'frame' => 7, 'color' => 'white', 'opacity' => 0]]],
    'tracks' => [['id' => 'body', 'type' => 'image', 'asset' => $asset, 'anchor' => 'stage',
      'placement' => ['position' => ['x' => 160, 'y' => 160], 'size' => ['width' => 80, 'height' => 80]],
      'pivot' => ['x' => .5, 'y' => 1], 'keyframes' => [['frame' => 1, 'duration' => 5]]]],
    'cues' => []];
}

beforeEach(function () {
  $this->root = createTestDirectory('battle-action-stage-');
  writeTestPng($this->root . '/source.png', 16, 16);
  writeTestPng($this->root . '/target.png', 16, 16);
  $this->validator = new EffectTimelineValidator($this->root);
  $this->source = $this->validator->compile('source-stage', makeBattleActionStageSource('source.png'), forBattle: true);
});

it('admits an ordinary source cut-in through the battle effect profile with independent terminal content', function () {
  $paired = ['presentations' => ['graphical' => makeBattleActionStageSource('source.png'),
    'terminal' => ['fps' => 10, 'lengthFrames' => 6, 'restFrame' => 0,
      'tracks' => [['id' => 'text', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'duration' => 6, 'content' => '*']]]]]]];
  $graphical = $this->validator->compile('ordinary-cut-in', $paired, true);
  $terminal = $this->validator->compile('ordinary-cut-in', $paired, true, EffectPresentation::TERMINAL);
  expect($graphical)->toBeInstanceOf(CompiledEffectTimeline::class)
    ->and($graphical->defaults)->toHaveKey('stage')->and($terminal->defaults)->not->toHaveKey('stage')
    ->and($terminal->hasTerminalContent)->toBeTrue();
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, 1, .1, .1, .1, .1, .1), source: $graphical);
  expect($plan->summon)->toBeNull()->and($plan->hasCinematicStage('source'))->toBeTrue()
    ->and($plan->hasCinematicStage('target'))->toBeFalse()
    ->and($plan->phases['source']['start'])->toBe($plan->phases['announce']['start'])
    ->and($plan->phases)->not->toHaveKeys(['summon-in', 'summon-title', 'summon-out']);
});

it('maps source and target stages to their own clocks, art and reduced-motion frame', function (bool $reduced) {
  $targetSource = makeBattleActionStageSource('target.png');
  $targetSource['cues'] = [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 4, 'payload' => []]];
  $target = $this->validator->compile('target-stage', $targetSource, forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .2, .1, .1, .1, .1, .1), $this->source, $target);
  foreach (['source' => 'source.png', 'target' => 'target.png'] as $lane => $asset) {
    $tick = $plan->getCommandFrameForAuthoredFrame($lane, 2);
    $frame = $plan->getCinematicStageFrame($tick, $reduced);
    expect($frame->contentFrame)->toBe($reduced ? 3 : 2);
    $canvas = CinematicStagePresentation::compose($frame, $plan->getCinematicStageSegments($tick), $this->root, 640, 360);
    expect(array_column($canvas->images, 'asset'))->toBe([$asset]);
  }
  expect($plan->getCinematicStageFrame($plan->phases['reaction']['start']))->toBeNull()
    ->and($plan->getCinematicStageSegments($plan->phases['reaction']['start']))->toBe([]);
})->with([false, true]);

it('keeps cut-in inspection pure, resolves at the target once, and cancels before impact without an outcome', function (bool $cancel) {
  $target = new CompiledEffectTimeline('impact', '', fps: 10,
    cueSchedule: [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 1, 'payload' => []]], defaults: ['lengthFrames' => 2]);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .2, .1, .1, .1, .1, .1), $this->source, $target);
  $actor = new Character('Synthetic actor', 1, new Stats());
  $hits = 0;
  $playback = new BattleCommandPlayback($plan, $actor, [$actor], BattlePoseRole::ATTACK,
    static function () use (&$hits): void { $hits++; }, static fn() => null);
  $playback->begin();
  $tick = $plan->getCommandFrameForAuthoredFrame('source', 2);
  $playback->update($tick / BattleCommandTimeline::FPS);
  for ($i = 0; $i < 3; $i++) {
    $plan->getCinematicStageFrame($tick);
    $plan->getCinematicStageSegments($tick);
  }
  expect($hits)->toBe(0);
  if ($cancel) { $playback->cancel(); }
  $playback->update(10); $playback->update(10);
  expect($hits)->toBe($cancel ? 0 : 1)->and($playback->isCompleted)->toBeTrue();
})->with([false, true]);

it('retains stage validation and keeps ordinary field effects out of the battle stage profile', function () {
  expect(fn() => $this->validator->compile('field', makeBattleActionStageSource('source.png')))->toThrow(InvalidArgumentException::class);
  $source = makeBattleActionStageSource('source.png');
  $source['cadence'] = 'battle_phase'; unset($source['fps']);
  expect(fn() => $this->validator->compile('paced', $source, forBattle: true))->toThrow(InvalidArgumentException::class);
});


it('keeps the command announcement over the source cut-in and restores controls on completion or disposal', function (bool $cancel, bool $dual) {
  require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';
  [, $context, $screen] = \Tests\Support\Battle\createTargetExecutionFixture(false, true,
    $this->createStub(\Ichiloto\Engine\Audio\AudioManager::class));
  $actor = $context->party->members->toArray()[0];
  $hits = 0;
  $runner = new \Ichiloto\Engine\Battle\Presentation\BattleCommandRunner($context, $actor, [], null,
    'Synthetic move', new BattleTurnTimings(.1, 1, .1, .1, .1, .1, .1), $this->source, $dual ? $this->source : null,
    static function () use (&$hits): void { $hits++; }, static fn() => [], static fn() => null);
  $runner->begin();
  $tick = $runner->playback->plan->getCommandFrameForAuthoredFrame('source', 2);
  $runner->update($tick / BattleCommandTimeline::FPS);
  expect($screen->announcement)->toBe('Synthetic move')->and($screen->controlsHidden)->toBe(1)
    ->and($screen->controlsRestored)->toBe(0)->and($hits)->toBe(0);
  if ($cancel) { $runner->dispose(); }
  else { $runner->update(10); }
  expect($screen->controlsRestored)->toBe(1)->and($hits)->toBe($cancel ? 0 : 1)->and($screen->controlsHidden)->toBe(1);
  $runner->dispose();
  expect($screen->controlsRestored)->toBe(1)->and($screen->announcement)->toBeNull();
})->with([false, true])->with([false, true]);

it('restores the arena and publishes the real targets at the authored impact for both cinematic consumers', function (bool $summon) {
  require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';
  [, $context, $screen] = \Tests\Support\Battle\createTargetExecutionFixture(false, true,
    $this->createStub(\Ichiloto\Engine\Audio\AudioManager::class));
  $actor = $context->party->members->toArray()[0];
  $data = makeBattleActionStageSource('source.png');
  $timing = ['mode' => 'arena', 'frame' => 7];
  $stage = $summon
    ? (new \Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneCompiler(assetRoot: $this->root))->compile(
      \Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition::fromArrays([
        'id' => 'summon-stage', 'name' => 'Synthetic summon', 'effectTiming' => $timing,
      ], $data))
    : $this->validator->compile('move-stage', [...$data, 'effectTiming' => $timing], forBattle: true);
  $hits = 0;
  $presented = [];
  $runner = new \Ichiloto\Engine\Battle\Presentation\BattleCommandRunner($context, $actor, [$actor], null,
    'Synthetic move', new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), null, $stage,
    static function () use (&$hits): void { $hits++; }, static fn() => [],
    static function (array $cue) use (&$presented): void {
      if ($cue['type'] === 'commandResult') { $presented[] = $cue; }
    });
  $runner->begin();
  $plan = $runner->playback->plan;
  $beforeRestore = $plan->getCommandFrameForAuthoredFrame('target', 5);
  $restore = $plan->getCommandFrameForAuthoredFrame('target', 6);
  $impact = $plan->getCommandFrameForAuthoredFrame('target', 7);
  $runner->update($beforeRestore / BattleCommandTimeline::FPS);
  expect($plan->getCinematicStageFrame($beforeRestore, true)->active)->toBeTrue()
    ->and($screen->controlsHidden)->toBe(1)->and($hits)->toBe(0);
  $runner->update(($restore - $beforeRestore) / BattleCommandTimeline::FPS);
  expect($plan->getCinematicStageFrame($restore, true)->active)->toBeFalse()
    ->and($screen->controlsRestored)->toBe(1)->and($hits)->toBe(0);
  $runner->update(($impact - $restore) / BattleCommandTimeline::FPS);
  expect($hits)->toBe(1)
    ->and(array_column($presented, 'frame'))->toBe([$impact])
    ->and($presented[0]['payload']['target'])->toBe($actor);
  $runner->update(10);
  expect($hits)->toBe(1)->and($screen->controlsRestored)->toBe(1);
})->with([false, true]);

it('rejects an arena impact hidden by the stage and accepts independent terminal timing', function () {
  $data = [...makeBattleActionStageSource('source.png'), 'effectTiming' => ['mode' => 'arena', 'frame' => 5]];
  expect(fn() => $this->validator->compile('hidden-impact', $data, forBattle: true))
    ->toThrow(InvalidArgumentException::class, 'after battlefield restoration');

  $terminal = $this->validator->compile('terminal-impact', [
    'fps' => 20, 'lengthFrames' => 4, 'effectTiming' => ['mode' => 'arena', 'frame' => 1],
    'tracks' => [['id' => 'hit', 'type' => 'glyph', 'keyframes' => [['frame' => 1, 'duration' => 1, 'content' => '*']]]],
  ], forBattle: true, presentation: EffectPresentation::TERMINAL);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $terminal);
  $cue = array_find($plan->timeline->cueSchedule, static fn(array $cue): bool => $cue['type'] === 'commandImpact');
  expect($cue['frame'])->toBe($plan->getCommandFrameForAuthoredFrame('target', 1))
    ->and($plan->getCinematicStageFrame($cue['frame']))->toBeNull();
});
