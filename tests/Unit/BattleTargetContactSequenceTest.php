<?php

use Ichiloto\Engine\Animations\Timelines\{EffectPresentation, EffectTimelineValidator};
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCanvasLayout, BattleCommandPlayback, BattleCommandTimeline, BattleContactDirection, BattleEffectTargetGroup, BattlePoseRole, BattleTargetContactSequence, GraphicalBattleEffects};
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Summons\{SummonCutsceneCompiler, SummonCutsceneDefinition};
use Ichiloto\Engine\Entities\{Character, Stats};
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->root = createTestDirectory('target-contact-sequence-');
  \Tests\Support\Rendering\writeTestPng($this->root . '/contact.png', 8, 8);
  $this->actor = new Character('Caster', 1, new Stats());
  $this->far = new Character('Same name', 1, new Stats());
  $this->near = new Character('Same name', 1, new Stats());
  $this->bystander = new Character('Same name', 1, new Stats());
  $this->bounds = [spl_object_id($this->far) => new CanvasRectangle(20, 20, 10, 20),
    spl_object_id($this->near) => new CanvasRectangle(80, 70, 20, 30),
    spl_object_id($this->bystander) => new CanvasRectangle(1100, 50, 30, 20)];
  $this->grounds = [spl_object_id($this->far) => new Vector2(24, 40),
    spl_object_id($this->near) => new Vector2(85, 100),
    spl_object_id($this->bystander) => new Vector2(1110, 70)];
  $this->data = ['fps' => 20, 'lengthFrames' => 16, 'restFrame' => 6,
    'effectTiming' => ['mode' => 'frame', 'frame' => 12],
    'targetContactSequence' => ['firstFrame' => 6, 'lastFrame' => 10, 'direction' => 'west', 'tracks' => ['splash']],
    'tracks' => [['id' => 'splash', 'type' => 'image', 'presentation' => 'graphical', 'asset' => 'contact.png',
      'anchor' => 'target', 'keyframes' => [['frame' => 6, 'duration' => 2]]]],
    'cues' => []];
});

it('orders captured visible body edges independently of names, depth and resolved target order', function (BattleContactDirection $direction) {
  $mid = new Character('Same name', 1, new Stats());
  $id = spl_object_id($mid);
  $bounds = $this->bounds + [$id => new CanvasRectangle(50, 150, 15, 25)];
  $grounds = $this->grounds + [$id => new Vector2(54, 175)];
  $group = BattleEffectTargetGroup::collectTargets([$this->far, $mid, $this->near, $this->far], $bounds, $grounds);
  $sequence = new BattleTargetContactSequence(100, 200, $direction, ['splash']);
  $frames = $sequence->getContactFrames($group);
  $expected = $direction === BattleContactDirection::WEST
    ? [spl_object_id($this->near) => 100, $id => 150, spl_object_id($this->far) => 200]
    : [spl_object_id($this->far) => 100, $id => 150, spl_object_id($this->near) => 200];
  expect($frames)->toBe($expected)->and($frames)->not->toHaveKey(spl_object_id($this->bystander));
})->with([BattleContactDirection::EAST, BattleContactDirection::WEST]);

it('gives coincident bodies a simultaneous contact and a single recipient the first contact', function () {
  $sequence = new BattleTargetContactSequence(10, 40, BattleContactDirection::WEST, ['splash']);
  $bounds = $this->bounds;
  $bounds[spl_object_id($this->far)] = $bounds[spl_object_id($this->near)];
  $group = BattleEffectTargetGroup::collectTargets([$this->far, $this->near], $bounds, $this->grounds);
  expect(array_values($sequence->getContactFrames($group)))->toBe([10, 10]);
  $group = BattleEffectTargetGroup::collectTargets([$this->far], $bounds, $this->grounds);
  expect($sequence->getContactFrames($group))->toBe([spl_object_id($this->far) => 10]);
});

it('plays body contacts before one logical impact through ordinary and summon timelines', function (bool $summon, bool $cancel, bool $reduced) {
  $data = $this->data;
  $data['effectTiming']['mode'] = 'arena';
  $data['stage'] = ['canvas' => ['width' => 320, 'height' => 180], 'startFrame' => 0, 'restoreFrame' => 4, 'restFrame' => 2,
    'camera' => [['id' => 'wide', 'frame' => 0, 'focus' => ['x' => 160, 'y' => 90], 'zoom' => 1]],
    'covers' => []];
  $data['tracks'][] = ['id' => 'stage', 'type' => 'image', 'presentation' => 'graphical', 'asset' => 'contact.png',
    'anchor' => 'stage', 'placement' => ['position' => ['x' => 160, 'y' => 90], 'size' => ['width' => 80, 'height' => 80]],
    'keyframes' => [['frame' => 0, 'duration' => 4]]];
  $effect = $summon ? (new SummonCutsceneCompiler(assetRoot: $this->root))->compile(
    SummonCutsceneDefinition::fromArrays(['id' => 'contact', 'name' => 'Synthetic', 'effectTiming' => $data['effectTiming']], $data))
    : (new EffectTimelineValidator($this->root))->compile('contacts', $data, forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect);
  $resolutions = 0;
  $captureFrames = [];
  $playback = new BattleCommandPlayback($plan, $this->actor, [$this->far, $this->near], BattlePoseRole::ATTACK,
    function () use (&$resolutions) { $resolutions++; }, static fn() => null);
  $playback->bindTargetGroupCapture(function (BattleCommandPlayback $sample) use (&$captureFrames) {
    $captureFrames[] = $sample->session->currentFrame;
    return BattleEffectTargetGroup::collectTargets($sample->targets, $this->bounds, $this->grounds);
  });
  $at = fn(int $frame): int => $plan->getCommandFrameForAuthoredFrame('target', $frame);
  $render = fn() => GraphicalBattleEffects::compose($playback, new BattleCanvasLayout(1440, 840), $this->bounds,
    $this->root, $reduced, groundAnchors: $this->grounds)->images;
  $playback->begin();
  $playback->update($at(5) / 120);
  expect($render())->toBeEmpty()->and($resolutions)->toBe(0);
  $playback->update(($at(6) - $at(5)) / 120);
  expect($captureFrames)->toBe([$at(6)])
    ->and($playback->getTargetContactFrames())->toBe([spl_object_id($this->near) => $at(6), spl_object_id($this->far) => $at(10)])
    ->and($render())->toHaveCount(1)->and($resolutions)->toBe(0)->and($playback->presentationFailure)->toBeNull();
  $snapshot = $playback->getTargetContactFrames();
  $render(); $render();
  expect($captureFrames)->toHaveCount(1)->and($playback->getTargetContactFrames())->toBe($snapshot)->and($resolutions)->toBe(0);
  if ($cancel) {
    $playback->cancel(); $playback->update(100);
    expect($playback->getTargetContactFrames())->toBeEmpty()->and($render())->toBeEmpty()->and($resolutions)->toBe(0);
    return;
  }
  $playback->update(($at(10) - $at(6)) / 120);
  expect($render())->toHaveCount(1)->and($resolutions)->toBe(0);
  $playback->update(($at(12) - $at(10)) / 120);
  expect($resolutions)->toBe(1)->and($render())->toBeEmpty();
  $playback->update(100);
  expect($resolutions)->toBe(1)->and($render())->toBeEmpty();
})->with([false, true])->with([false, true])->with([false, true]);

it('reports missing body capture while preserving one logical impact', function () {
  $effect = (new EffectTimelineValidator($this->root))->compile('contacts', $this->data, forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect);
  $hits = 0;
  $playback = new BattleCommandPlayback($plan, $this->actor, [$this->near], BattlePoseRole::ATTACK,
    function () use (&$hits) { $hits++; }, static fn() => null);
  $playback->begin(); $playback->update(100);
  expect($hits)->toBe(1)->and($playback->presentationFailure)->toBeInstanceOf(RuntimeException::class);
});

it('keeps Terminal content independent of graphical contact resources and timing', function () {
  $data = $this->data;
  $data['tracks'][] = ['id' => 'terminal', 'type' => 'glyph', 'presentation' => 'terminal',
    'keyframes' => [['frame' => 0, 'duration' => 16, 'content' => '*']]];
  unlink($this->root . '/contact.png');
  $effect = (new EffectTimelineValidator($this->root))->compile('contacts', $data, true, EffectPresentation::TERMINAL);
  expect($effect->defaults)->not->toHaveKey('targetContactSequence')->and($effect->hasTerminalContent)->toBeTrue();
});

it('refuses invalid or overflowing track references at the authoring boundary', function (array $change) {
  $data = $this->data;
  $data['targetContactSequence'] = [...$data['targetContactSequence'], ...$change];
  expect(fn() => (new EffectTimelineValidator($this->root))->compile('contacts', $data, forBattle: true))
    ->toThrow(InvalidArgumentException::class);
})->with(array_map(static fn(array $change): array => [$change], [['firstFrame' => -1], ['lastFrame' => 16], ['lastFrame' => 5], ['direction' => 'up'],
  ['tracks' => []], ['tracks' => ['missing']], ['tracks' => ['splash', 'splash']], ['lastFrame' => 15]]));


it('binds real projected bodies in runtime and scrubbed preview without replaying combat', function (bool $activeTime, bool $reduced, bool $captured) {
  require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';
  [, $context, , $actor, , $targets] = \Tests\Support\Battle\createTargetExecutionFixture($activeTime, true,
    $this->createStub(\Ichiloto\Engine\Audio\AudioManager::class),
    [getStageSlot(150, 220, 60, 90), getStageSlot(430, 410, 90, 110)]);
  foreach ($targets as $target) {
    foreach (['level' => 1, 'image' => ['x'], 'imagePath' => ''] as $key => $value) {
      new ReflectionProperty(\Ichiloto\Engine\Entities\Enemies\Enemy::class, $key)->setValue($target, $value);
    }
  }
  $battle = new \Ichiloto\Engine\Scenes\Battle\BattleConfig($context->party, $context->troop);
  $art = new \Ichiloto\Engine\Battle\Presentation\BattlerArtwork('contact.png', 8, 8, 4, 8);
  $catalog = new \Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog([
    'synthetic' => new \Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition('Synthetic',
      new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage('arena', 'contact.png', new CanvasRectangle(0, 0, 1440, 840))),
  ], ['Caster' => $art, 'Recipient' => $art], ['Enemy A' => $art, 'Enemy B' => $art], ui: new BattleCanvasLayout(1440, 840,
    partySlots: [getStageSlot(1000, 300, 80, 120), getStageSlot(1150, 420, 80, 120)]), defaultArena: 'synthetic');
  $data = $this->data;
  if ($captured) { $data['targetCaptureFrame'] = 5; }
  $effect = (new EffectTimelineValidator($this->root))->compile('contacts', $data, forBattle: true);
  $runner = new \Ichiloto\Engine\Battle\Presentation\BattleCommandRunner($context, $actor, $targets, null, 'Synthetic',
    new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), null, $effect,
    static function () use ($targets): void { foreach ($targets as $target) { $target->stats->currentHp -= 10; } },
    static fn() => [], static fn() => null);
  $presentation = \Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  expect($presentation)->not->toBeNull()->and($runner->playback->plan->requiresGraphicalAnchorCapture())->toBeTrue();
  // Supply the screen's scene owner without creating a native host. begin() binds through BattleScreen.
  $scene = new ReflectionClass(\Ichiloto\Engine\Scenes\Battle\BattleScene::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(\Ichiloto\Engine\Scenes\Battle\BattleScene::class, 'graphicalPresentation')->setValue($scene, $presentation);
  new ReflectionProperty(\Ichiloto\Engine\Battle\UI\BattleScreen::class, 'battleScene')->setValue($context->ui, $scene);
  $runner->begin();
  $plan = $runner->playback->plan;
  $first = $plan->getCommandFrameForAuthoredFrame('target', 6);
  $last = $plan->getCommandFrameForAuthoredFrame('target', 10);
  $runner->update($first / 120);
  $frames = $runner->playback->getTargetContactFrames();
  expect(array_keys($frames))->toBe([spl_object_id($targets[1]), spl_object_id($targets[0])])
    ->and(array_values($frames))->toBe([$first, $last])
    ->and(array_map(static fn($target) => $target->stats->currentHp, $targets))->toBe([100, 100])
    ->and($runner->playback->presentationFailure)->toBeNull();
  if ($captured) {
    expect(array_keys($runner->playback->getCapturedTargetGroup('snapshot')->memberBounds))
      ->toBe(array_map(spl_object_id(...), $targets));
  }
  $preview = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPreview($battle, $plan, $actor, $targets,
    BattlePoseRole::ATTACK, $catalog, $this->root);
  $farFrame = $preview->getFrameAtIndex($last, $reduced);
  $nearFrame = $preview->getFrameAtIndex($first, $reduced);
  $contactImages = static fn($frame) => array_values(array_filter($frame->canvas->images,
    static fn($image): bool => str_contains($image->id, 'target-splash')));
  expect($nearFrame->diagnostics)->toBe([])->and($farFrame->diagnostics)->toBe([])
    ->and($contactImages($nearFrame))->toHaveCount(1)->and($contactImages($farFrame))->toHaveCount(1)
    ->and(array_map(static fn($target) => $target->stats->currentHp, $targets))->toBe([100, 100]);
  $runner->update(100);
  expect(array_map(static fn($target) => $target->stats->currentHp, $targets))->toBe([90, 90]);
  $runner->update(100); $runner->dispose();
  expect(array_map(static fn($target) => $target->stats->currentHp, $targets))->toBe([90, 90]);
})->with([false, true])->with([false, true])->with([false, true]);

it('preserves the flat summon contact descriptor in its authoring round trip', function () {
  $definition = SummonCutsceneDefinition::fromArrays(['id' => 'contacts', 'name' => 'Synthetic'], $this->data);
  expect($definition->toTimelineArray()['targetContactSequence'])->toBe($this->data['targetContactSequence']);
});


it('owns front capture separately from an effect-start group snapshot', function () {
  $data = $this->data;
  $data['tracks'][] = ['id' => 'ground', 'type' => 'image', 'presentation' => 'graphical', 'asset' => 'contact.png',
    'anchor' => 'target-group', 'anchorLifetime' => 'effect-start', 'attachment' => 'ground',
    'keyframes' => [['frame' => 0, 'duration' => 16]]];
  $effect = (new EffectTimelineValidator($this->root))->compile('contacts', $data, forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect);
  $hits = 0; $captures = [];
  $playback = new BattleCommandPlayback($plan, $this->actor, [$this->far, $this->near], BattlePoseRole::ATTACK,
    function () use (&$hits): void { $hits++; }, static fn() => null);
  $playback->bindTargetGroupCapture(function ($sample) use (&$captures) {
    $captures[] = $sample->session->currentFrame;
    return BattleEffectTargetGroup::collectTargets($sample->targets, $this->bounds, $this->grounds);
  });
  $playback->begin();
  $start = $plan->phases['target']['start'];
  $first = $plan->getCommandFrameForAuthoredFrame('target', 6);
  $last = $plan->getCommandFrameForAuthoredFrame('target', 10);
  $playback->update($start / 120);
  $original = $playback->getCapturedTargetGroup('target');
  $this->bounds[spl_object_id($this->far)] = new CanvasRectangle(300, 30, 30, 50);
  $playback->update(($first - $start) / 120);
  expect($captures)->toBe([$start, $first])->and($playback->getCapturedTargetGroup('target'))->toBe($original)
    ->and($original->memberBounds[spl_object_id($this->far)]->x)->toBe(20.0)
    ->and($playback->getTargetContactFrames())->toBe([spl_object_id($this->far) => $first, spl_object_id($this->near) => $last])
    ->and($hits)->toBe(0);
});

it('rejects source-lane contact sequencing before playback starts', function () {
  $effect = (new EffectTimelineValidator($this->root))->compile('contacts', $this->data, forBattle: true);
  expect(fn() => new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), source: $effect))
    ->toThrow(InvalidArgumentException::class, 'target effect lane');
});


it('samples target flash and shake tracks on the same captured contact clocks', function (string $type) {
  $data = $this->data;
  $data['tracks'][0] = ['id' => 'splash', 'type' => $type, 'anchor' => 'target', 'presentation' => 'graphical',
    'keyframes' => [['frame' => 6, 'duration' => 2, 'payload' => $type === 'shake' ? ['amplitude' => 2] : ['color' => 'white', 'opacity' => 1]]]];
  $effect = (new EffectTimelineValidator($this->root))->compile('contacts', $data, forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect);
  $playback = new BattleCommandPlayback($plan, $this->actor, [$this->far, $this->near], BattlePoseRole::ATTACK,
    static fn() => null, static fn() => null);
  $playback->bindTargetGroupCapture(fn() => BattleEffectTargetGroup::collectTargets([$this->far, $this->near], $this->bounds, $this->grounds));
  $playback->begin();
  $playback->update(($plan->getCommandFrameForAuthoredFrame('target', 6) + 3) / 120);
  $contacts = array_values(array_filter($playback->getActiveSegments(), static fn(array $segment): bool => $segment['layer'] === $type));
  expect($contacts)->toHaveCount(1)->and($contacts[0]['drawCommands'][0]['payload']['hitSubject'])->toBe(spl_object_id($this->near));
  if ($type === 'shake') {
    expect($playback->getShakeFraction($this->near, false))->not->toBe(0.0)
      ->and($playback->getShakeFraction($this->far, false))->toBe(0.0);
  }
})->with(['flash', 'shake']);

it('refuses screen-wide or caster payloads on sequenced target tracks', function (array $payload) {
  $data = $this->data;
  $data['tracks'][0] = ['id' => 'splash', 'type' => 'flash', 'presentation' => 'graphical',
    'keyframes' => [['frame' => 6, 'duration' => 2, 'payload' => $payload]]];
  expect(fn() => (new EffectTimelineValidator($this->root))->compile('contacts', $data, forBattle: true))
    ->toThrow(InvalidArgumentException::class);
})->with([[['scope' => 'screen']], [['anchor' => 'caster']]]);


it('preserves graphical contact authoring in a paired summon and refuses a lossy mixed round trip', function () {
  $paired = ['presentations' => ['graphical' => $this->data, 'terminal' => [
    'fps' => 20, 'lengthFrames' => 16, 'restFrame' => 0,
    'tracks' => [['id' => 'terminal', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'duration' => 16, 'content' => '*']]]],
  ]]];
  $definition = SummonCutsceneDefinition::fromArrays(['id' => 'paired', 'name' => 'Synthetic',
    'effectTiming' => $this->data['effectTiming']], $paired);
  expect($definition->toTimelineArray()['presentations'])->toBe($paired['presentations']);
  $compiler = new SummonCutsceneCompiler(assetRoot: $this->root);
  expect($compiler->compile($definition)->defaults['targetContactSequence'])->toBe($this->data['targetContactSequence'])
    ->and($compiler->compile($definition, EffectPresentation::TERMINAL)->defaults)->not->toHaveKey('targetContactSequence');
  $definition->targetContactSequence = $this->data['targetContactSequence'];
  expect(fn() => $definition->toTimelineArray())->toThrow(InvalidArgumentException::class, 'graphical presentation');
});
