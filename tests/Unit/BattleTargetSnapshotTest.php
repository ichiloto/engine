<?php

use Ichiloto\Engine\Animations\Timelines\{EffectPresentation, EffectTimelineValidator};
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCanvasLayout, BattleCommandPlayback, BattleCommandTimeline, BattleEffectTargetGroup, BattlePoseRole, GraphicalBattleEffects};
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Summons\{SummonCutsceneCompiler, SummonCutsceneDefinition};
use Ichiloto\Engine\Entities\{Character, Stats};
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->root = createTestDirectory('target-snapshot-');
  \Tests\Support\Rendering\writeTestPng($this->root . '/effect.png', 8, 8);
  $this->actor = new Character('Caster', 1, new Stats());
  $this->first = new Character('Same name', 1, new Stats());
  $this->second = new Character('Same name', 1, new Stats());
  $this->bounds = [spl_object_id($this->first) => new CanvasRectangle(80, 70, 20, 30),
    spl_object_id($this->second) => new CanvasRectangle(20, 20, 10, 20)];
  $this->grounds = [spl_object_id($this->first) => new Vector2(85, 100),
    spl_object_id($this->second) => new Vector2(24, 40)];
  $this->data = ['fps' => 20, 'lengthFrames' => 16, 'restFrame' => 6,
    'targetCaptureFrame' => 5, 'effectTiming' => ['mode' => 'frame', 'frame' => 12],
    'targetContactSequence' => ['firstFrame' => 6, 'lastFrame' => 10, 'direction' => 'west', 'tracks' => ['contact']],
    'tracks' => [
      ['id' => 'contact', 'type' => 'image', 'presentation' => 'graphical', 'asset' => 'effect.png',
        'anchor' => 'target', 'keyframes' => [['frame' => 6, 'duration' => 2]]],
      ['id' => 'ground', 'type' => 'image', 'presentation' => 'graphical', 'asset' => 'effect.png',
        'anchor' => 'target-group', 'attachment' => 'ground', 'anchorLifetime' => 'target-capture',
        'keyframes' => [['frame' => 6, 'duration' => 10]]],
    ], 'cues' => []];
});

it('shares the earlier selected-body snapshot between contacts and fixed ground effects', function (bool $summon, bool $coarse, bool $cancel) {
  $effect = $summon ? (new SummonCutsceneCompiler(assetRoot: $this->root))->compile(
    SummonCutsceneDefinition::fromArrays(['id' => 'snapshot', 'name' => 'Synthetic', 'effectTiming' => $this->data['effectTiming']], $this->data))
    : (new EffectTimelineValidator($this->root))->compile('snapshot', $this->data, forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect);
  $hits = 0; $captures = [];
  $playback = new BattleCommandPlayback($plan, $this->actor, [$this->second, $this->first], BattlePoseRole::ATTACK,
    function () use (&$hits) { $hits++; }, static fn() => null);
  $playback->bindTargetGroupCapture(function ($sample) use (&$captures) {
    $captures[] = $sample->session->currentFrame;
    return BattleEffectTargetGroup::collectTargets($sample->targets, $this->bounds, $this->grounds);
  });
  $at = fn(int $frame): int => $plan->getCommandFrameForAuthoredFrame('target', $frame);
  $playback->begin();
  $playback->update($at(5) / 120);
  $snapshot = $playback->getCapturedTargetGroup('snapshot');
  expect($captures)->toBe([$at(5)])->and($snapshot)->not->toBeNull()->and($hits)->toBe(0);
  $this->bounds[spl_object_id($this->second)] = new CanvasRectangle(300, 30, 30, 50);
  $this->grounds[spl_object_id($this->second)] = new Vector2(315, 80);
  if ($cancel) {
    $playback->cancel(); $playback->update(100);
    expect($playback->getCapturedTargetGroup('snapshot'))->toBeNull()
      ->and($playback->getTargetContactFrames())->toBeEmpty()->and($hits)->toBe(0);
    return;
  }
  $playback->update(($at($coarse ? 10 : 6) - $at(5)) / 120);
  expect($captures)->toBe([$at(5)])
    ->and($playback->getTargetContactFrames())->toBe([spl_object_id($this->first) => $at(6), spl_object_id($this->second) => $at(10)])
    ->and($playback->getCapturedTargetGroup('snapshot'))->toBe($snapshot)->and($hits)->toBe(0);
  $images = GraphicalBattleEffects::compose($playback, new BattleCanvasLayout(1440, 840), $this->bounds,
    $this->root, false, groundAnchors: $this->grounds)->images;
  $ground = array_find($images, static fn($image) => str_contains($image->id, 'target-ground'));
  expect($ground)->not->toBeNull()->and($ground->destination->x + $ground->destination->width / 2)->toBe(54.5)
    ->and($snapshot->grounds[spl_object_id($this->second)]->x)->toBe(24.0)
    ->and($playback->presentationFailure)->toBeNull();
  $playback->update(100); $playback->update(100);
  expect($hits)->toBe(1)->and($captures)->toHaveCount(1);
})->with([false, true])->with([false, true])->with([false, true]);

it('keeps simultaneous contacts at their own clock after an earlier snapshot', function () {
  $data = $this->data;
  $data['targetContactSequence']['lastFrame'] = 6;
  $effect = (new EffectTimelineValidator($this->root))->compile('snapshot', $data, forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect);
  $playback = new BattleCommandPlayback($plan, $this->actor, [$this->first, $this->second], BattlePoseRole::ATTACK,
    static fn() => null, static fn() => null);
  $playback->bindTargetGroupCapture(fn($sample) => BattleEffectTargetGroup::collectTargets($sample->targets, $this->bounds, $this->grounds));
  $playback->begin();
  $frame = $plan->getCommandFrameForAuthoredFrame('target', 6);
  $playback->update($frame / 120);
  expect(array_values($playback->getTargetContactFrames()))->toBe([$frame, $frame])
    ->and($playback->presentationFailure)->toBeNull();
});

it('supports a captured group without any contact sequence and preserves source round trips', function (bool $paired) {
  $data = $this->data;
  unset($data['targetContactSequence']);
  $timeline = $paired ? ['presentations' => ['graphical' => $data, 'terminal' => [
    'fps' => 20, 'lengthFrames' => 16, 'tracks' => [['id' => 'text', 'type' => 'glyph',
      'keyframes' => [['frame' => 0, 'duration' => 16, 'content' => '*']]]],
  ]]] : $data;
  $definition = SummonCutsceneDefinition::fromArrays(['id' => 'snapshot', 'name' => 'Synthetic',
    'effectTiming' => $data['effectTiming']], $timeline);
  $roundTrip = $definition->toTimelineArray();
  $selected = $paired ? $roundTrip['presentations']['graphical'] : $roundTrip;
  expect($selected['targetCaptureFrame'])->toBe(5)
    ->and($selected['tracks'][1]['anchorLifetime'])->toBe('target-capture');
  $compiled = (new SummonCutsceneCompiler(assetRoot: $this->root))->compile($definition);
  expect($compiled->defaults['targetCaptureFrame'])->toBe(5);
  $effect = (new EffectTimelineValidator($this->root))->compile('snapshot', $data, forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect);
  expect(array_values(array_filter(array_column($plan->timeline->cueSchedule, 'type'),
    static fn($type) => str_starts_with($type, 'commandCapture'))))->toBe(['commandCaptureTargetSnapshot']);
})->with([false, true]);

it('rejects invalid snapshot clocks before playback', function (mixed $frame) {
  $data = $this->data; $data['targetCaptureFrame'] = $frame;
  expect(fn() => (new EffectTimelineValidator($this->root))->compile('snapshot', $data, forBattle: true))
    ->toThrow(InvalidArgumentException::class);
})->with([-1, 16, 7, null, true, 5.5, '5']);

it('requires an authored snapshot before a snapshot-bound image is visible', function (bool $missing) {
  $data = $this->data;
  if ($missing) { unset($data['targetCaptureFrame']); }
  else { $data['tracks'][1]['keyframes'][0]['frame'] = 4; }
  expect(fn() => (new EffectTimelineValidator($this->root))->compile('snapshot', $data, forBattle: true))
    ->toThrow(InvalidArgumentException::class);
})->with([false, true]);

it('leaves Terminal independent of snapshot resources and source-lane playback refuses target capture', function () {
  $effect = (new EffectTimelineValidator($this->root))->compile('snapshot', $this->data, forBattle: true);
  expect(fn() => new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), source: $effect))
    ->toThrow(InvalidArgumentException::class, 'target effect lane');
  unlink($this->root . '/effect.png');
  $terminal = (new EffectTimelineValidator($this->root))->compile('snapshot', $this->data, true, EffectPresentation::TERMINAL);
  expect($terminal->defaults)->not->toHaveKey('targetCaptureFrame')->not->toHaveKey('targetContactSequence');
});


it('retains an empty snapshot and reports capture failure without replaying combat', function (bool $empty) {
  $effect = (new EffectTimelineValidator($this->root))->compile('snapshot', $this->data, forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect);
  $hits = 0;
  $playback = new BattleCommandPlayback($plan, $this->actor, $empty ? [] : [$this->first], BattlePoseRole::ATTACK,
    function () use (&$hits) { $hits++; }, static fn() => null);
  if ($empty) { $playback->bindTargetGroupCapture(static fn() => null); }
  $playback->begin(); $playback->update(100); $playback->update(100);
  expect($hits)->toBe(1)->and($playback->getTargetContactFrames())->toBeEmpty();
  if ($empty) { expect($playback->presentationFailure)->toBeNull(); }
  else { expect($playback->presentationFailure)->toBeInstanceOf(RuntimeException::class); }
})->with([false, true]);
