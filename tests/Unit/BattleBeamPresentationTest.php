<?php

use Ichiloto\Engine\Animations\Timelines\{EffectPresentation, EffectTimelineValidator};
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCanvasLayout, BattleCommandPlayback, BattleCommandTimeline, BattleEffectTargetGroup, BattlePoseRole, BattlePresentationPath, GraphicalBattleEffects};
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\{Character, Stats};
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

/** Project original source pixels through clipping, mirroring and the retained rigid rotation. */
function projectBeamSourcePoint(\Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage $image, float $x, float $y): Vector2
{
  $u = ($x - $image->sourceRect->x) / $image->sourceRect->width;
  $v = ($y - $image->sourceRect->y) / $image->sourceRect->height;
  if ($image->flipX) { $u = 1 - $u; }
  if ($image->flipY) { $v = 1 - $v; }
  if ($image->rotation === null) {
    return new Vector2($image->destination->x + $u * $image->destination->width,
      $image->destination->y + $v * $image->destination->height);
  }
  $angle = deg2rad($image->rotation);
  $dx = ($u - $image->pivot->x) * $image->destination->width;
  $dy = ($v - $image->pivot->y) * $image->destination->height;
  return new Vector2($image->destination->x + $image->pivot->x * $image->destination->width + $dx * cos($angle) - $dy * sin($angle),
    $image->destination->y + $image->pivot->y * $image->destination->height + $dx * sin($angle) + $dy * cos($angle));
}

beforeEach(function () {
  $this->root = createTestDirectory('captured-beam-');
  \Tests\Support\Rendering\writeTestPng($this->root . '/beam.png', 400, 40);
  $this->actor = new Character('Caster', 1, new Stats());
  $this->targets = [new Character('Same name', 1, new Stats()), new Character('Same name', 1, new Stats())];
  $this->bounds = [spl_object_id($this->targets[0]) => new CanvasRectangle(300, 60, 40, 60),
    spl_object_id($this->targets[1]) => new CanvasRectangle(500, 450, 40, 60)];
  $this->grounds = [spl_object_id($this->targets[0]) => new Vector2(320, 120),
    spl_object_id($this->targets[1]) => new Vector2(520, 510)];
  $this->data = ['fps' => 20, 'lengthFrames' => 12, 'restFrame' => 6, 'targetCaptureFrame' => 5,
    'effectTiming' => ['mode' => 'frame', 'frame' => 6],
    'targetContactSequence' => ['firstFrame' => 6, 'lastFrame' => 6, 'direction' => 'west', 'tracks' => ['hit']],
    'tracks' => [
      ['id' => 'beam', 'type' => 'image', 'presentation' => 'graphical', 'asset' => 'beam.png',
        'anchor' => 'caster', 'attachmentPoint' => 'release', 'detachFrame' => 5, 'facing' => 'west',
        'pivot' => ['x' => .9, 'y' => .5],
        'flight' => ['mode' => 'beam', 'route' => 'straight', 'tipX' => .1, 'arrivalFrame' => 6, 'fallback' => 'horizontal-exit'],
        'keyframes' => [['frame' => 6, 'duration' => 3]]],
      ['id' => 'hit', 'type' => 'image', 'presentation' => 'graphical', 'asset' => 'beam.png',
        'anchor' => 'target', 'keyframes' => [['frame' => 6, 'duration' => 3]]],
    ], 'cues' => []];
  $this->createPlayback = function (array $data, bool $fallback = false, bool $mirror = false, ?array $end = null): array {
    $effect = (new EffectTimelineValidator($this->root))->compile('captured-beam', $data, forBattle: true);
    $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect);
    $observed = (object)['hits' => 0, 'bodyCaptures' => 0, 'routeCaptures' => 0];
    $playback = new BattleCommandPlayback($plan, $this->actor, $this->targets, BattlePoseRole::ATTACK,
      static function () use ($observed) { $observed->hits++; }, static fn() => null);
    $start = [$mirror ? 200 : 900, 300];
    $end ??= [$mirror ? ($fallback ? 1351 : 1250) : ($fallback ? -1 : 100), 300];
    $path = new BattlePresentationPath([$start, $end]);
    $playback->bindTargetGroupCapture(function ($sample) use ($observed) {
      $observed->bodyCaptures++;
      return BattleEffectTargetGroup::collectTargets($sample->targets, $this->bounds, $this->grounds);
    });
    $playback->bindPosePointCapture(function () use ($observed, $path, $start, $end, $fallback, $mirror): array {
      $observed->routeCaptures++;
      return [spl_object_id($this->actor) => ['point' => new Vector2(...$start), 'casterX' => $start[0],
        'recipientX' => $end[0], 'party' => !$mirror, 'path' => $path,
        'fallback' => $fallback ? \Ichiloto\Engine\Animations\Timelines\EffectStraightFlightFallback::HORIZONTAL_EXIT : null,
        'targetOrder' => array_map(spl_object_id(...), $this->targets)]];
    });
    $playback->begin();
    return [$playback, $observed, $start, $end];
  };
  $this->compose = fn($playback, bool $reduced = false, bool $rotation = true, bool $clips = true) => GraphicalBattleEffects::compose(
    $playback, new BattleCanvasLayout(1350, 720), $this->bounds, $this->root, $reduced,
    groundAnchors: $this->grounds, imageRotation: $rotation, imageClips: $clips);
});

it('holds the captured straight beam at contact, including horizontal exits and reduced motion', function (bool $reduced, bool $fallback, bool $mirror) {
  [$playback, $observed, $start, $end] = ($this->createPlayback)($this->data, $fallback, $mirror);
  $at = fn(int $frame): int => $playback->plan->getCommandFrameForAuthoredFrame('target', $frame);
  $playback->update($at(5) / 120);
  expect(($this->compose)($playback, $reduced)->images)->toBeEmpty()
    ->and($observed->hits)->toBe(0)->and($observed->bodyCaptures)->toBe(1)->and($observed->routeCaptures)->toBe(1);
  $playback->update(($at(6) - $at(5)) / 120);
  $frame = ($this->compose)($playback, $reduced);
  $beam = array_find($frame->images, static fn($image) => str_contains($image->id, 'target-beam'));
  expect($beam)->not->toBeNull()->and($observed->hits)->toBe(1)
    ->and(array_values($playback->getTargetContactFrames()))->toBe([$at(6), $at(6)])
    ->and($playback->getFallbackHitFrames())->toBeEmpty()->and($playback->targets)->toBe($this->targets)
    ->and($playback->presentationFailure)->toBeNull();
  $point = static fn(float $sourceX): float => $beam->destination->x + ($beam->flipX
    ? $beam->sourceRect->x + $beam->sourceRect->width - $sourceX : $sourceX - $beam->sourceRect->x)
    * $beam->destination->width / $beam->sourceRect->width;
  expect($point(360))->toEqualWithDelta($start[0], .000001)
    ->and($point(40))->toEqualWithDelta($end[0], .000001)
    ->and($beam->destination->width / $beam->sourceRect->width)
    ->toEqualWithDelta($beam->destination->height / $beam->sourceRect->height, .000001);
  ($this->compose)($playback, $reduced);
  $playback->update(($at(7) - $at(6)) / 120);
  expect(array_find(($this->compose)($playback, $reduced)->images,
    static fn($image) => str_contains($image->id, 'target-beam')))->not->toBeNull();
  $playback->update(100); $playback->update(100);
  expect(($this->compose)($playback, $reduced)->images)->toBeEmpty()->and($observed->hits)->toBe(1)
    ->and($observed->bodyCaptures)->toBe(1)->and($observed->routeCaptures)->toBe(1);
})->with([false, true])->with([false, true])->with([false, true]);

it('reads replaceable source proportions while retaining the captured release and reach', function () {
  [$playback] = ($this->createPlayback)($this->data);
  $playback->update($playback->plan->getCommandFrameForAuthoredFrame('target', 6) / 120);
  $find = fn() => array_find(($this->compose)($playback)->images, static fn($image) => str_contains($image->id, 'target-beam'));
  $before = $find();
  \Tests\Support\Rendering\writeTestPng($this->root . '/beam.png', 800, 160);
  clearstatcache(true, $this->root . '/beam.png');
  $after = $find();
  expect($after->destination->width)->toEqualWithDelta($before->destination->width, .000001)
    ->and($after->destination->height)->toEqualWithDelta(2 * $before->destination->height, .000001)
    ->and($playback->presentationFailure)->toBeNull();
});

it('retains a diagonal captured ray and diagnoses missing rotation capability', function (bool $rotation, bool $clips) {
  [$playback, $observed] = ($this->createPlayback)($this->data, end: [500, -100]);
  $playback->update($playback->plan->getCommandFrameForAuthoredFrame('target', 6) / 120);
  $beam = array_find(($this->compose)($playback, rotation: $rotation, clips: $clips)->images,
    static fn($image) => str_contains($image->id, 'target-beam'));
  if (!$rotation || !$clips) {
    expect($beam)->toBeNull()->and($playback->presentationFailure)->toBeInstanceOf(RuntimeException::class)
      ->and($observed->hits)->toBe(1);
    return;
  }
  expect($beam)->not->toBeNull()->and($beam->rotation)->toBe(45)
    ->and($beam->destination->width / $beam->destination->height)->toEqualWithDelta(10, .000001)
    ->and($playback->presentationFailure)->toBeNull()->and($observed->hits)->toBe(1);
})->with([false, true])->with([false, true]);

it('cancels captured beam ownership without resolving an unhit command', function (bool $afterImpact) {
  [$playback, $observed] = ($this->createPlayback)($this->data, true);
  $playback->update($playback->plan->getCommandFrameForAuthoredFrame('target', $afterImpact ? 6 : 5) / 120);
  $playback->cancel(); $playback->update(100);
  expect(($this->compose)($playback)->images)->toBeEmpty()
    ->and($playback->getCapturedTargetGroup('snapshot'))->toBeNull()
    ->and($observed->hits)->toBe($afterImpact ? 1 : 0);
})->with([false, true]);

it('rejects contradictory beam authoring', function (array $change) {
  $data = $this->data;
  $data['tracks'][0]['flight'] = [...$data['tracks'][0]['flight'], ...$change];
  expect(fn() => (new EffectTimelineValidator($this->root))->compile('beam', $data, forBattle: true))
    ->toThrow(InvalidArgumentException::class);
})->with(array_map(static fn($change) => [$change], [
  ['mode' => null], ['mode' => 'unknown'], ['route' => 'waypoints'], ['tipX' => .9], ['tipX' => .95], ['holdFrame' => 5],
]));

it('keeps beams out of distance clocks and staggered fallback policy', function (bool $spacing) {
  $data = $this->data;
  unset($data['targetCaptureFrame'], $data['targetContactSequence']);
  if ($spacing) { $data['fallbackHitSpacingFrames'] = 1; }
  else { $data['flightTiming'] = ['track' => 'beam', 'speed' => 1]; }
  expect(EffectTimelineValidator::canTimeFlightFromTrack($data['tracks'][0]))->toBeFalse();
  expect(fn() => (new EffectTimelineValidator($this->root))->compile('beam', $data, forBattle: true))
    ->toThrow(InvalidArgumentException::class);
})->with([false, true]);

it('keeps Terminal independent of graphical beam files and geometry', function () {
  $data = $this->data;
  $data['tracks'][] = ['id' => 'terminal', 'type' => 'glyph', 'presentation' => 'terminal',
    'keyframes' => [['frame' => 0, 'duration' => 12, 'content' => '==>']]];
  unlink($this->root . '/beam.png');
  $effect = (new EffectTimelineValidator($this->root))->compile('beam', $data, true, EffectPresentation::TERMINAL);
  expect($effect->hasTerminalContent)->toBeTrue()->and($effect->defaults)->not->toHaveKey('targetCaptureFrame');
});


it('keeps measured release and tip coordinates through authored flips', function (bool $flipX, bool $flipY, bool $mirror) {
  $data = $this->data;
  $data['tracks'][0]['keyframes'][0]['flipX'] = $flipX;
  $data['tracks'][0]['keyframes'][0]['flipY'] = $flipY;
  [$playback, , $start, $end] = ($this->createPlayback)($data, mirror: $mirror);
  $playback->update($playback->plan->getCommandFrameForAuthoredFrame('target', 6) / 120);
  $beam = array_find(($this->compose)($playback)->images, static fn($image) => str_contains($image->id, 'target-beam'));
  expect($beam)->not->toBeNull()->and($playback->presentationFailure)->toBeNull();
  $rear = projectBeamSourcePoint($beam, 360, 20);
  $tip = projectBeamSourcePoint($beam, 40, 20);
  expect($rear->x)->toEqualWithDelta($start[0], .000001)->and($rear->y)->toEqualWithDelta($start[1], .000001)
    ->and($tip->x)->toEqualWithDelta($end[0], .000001)->and($tip->y)->toEqualWithDelta($end[1], .000001);
})->with([false, true])->with([false, true])->with([false, true]);

it('preserves beam authoring in flat and paired summon sources', function (bool $paired) {
  $timeline = $paired ? ['presentations' => ['graphical' => $this->data, 'terminal' => [
    'fps' => 20, 'lengthFrames' => 12, 'tracks' => [['id' => 'terminal', 'type' => 'glyph',
      'keyframes' => [['frame' => 0, 'duration' => 12, 'content' => '==>']]]],
  ]]] : $this->data;
  $definition = \Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition::fromArrays([
    'id' => 'beam', 'name' => 'Synthetic', 'effectTiming' => $this->data['effectTiming']], $timeline);
  $source = $definition->toTimelineArray();
  $selected = $paired ? $source['presentations']['graphical'] : $source;
  expect($selected['tracks'][0]['flight'])->toBe($this->data['tracks'][0]['flight']);
  $compiled = (new \Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneCompiler(assetRoot: $this->root))->compile($definition);
  $draws = array_merge(...array_column($compiled->playbackSegments, 'drawCommands'));
  $beam = array_find($draws, static fn($draw) => ($draw['payload']['flight']['mode'] ?? null) === 'beam');
  expect($beam)->not->toBeNull()->and($beam['payload']['flight'])->toBe($this->data['tracks'][0]['flight']);
})->with([false, true]);


it('diagnoses malformed captured beam routes without changing the command outcome', function (bool $bent) {
  [$playback, $observed] = ($this->createPlayback)($this->data);
  $playback->bindPosePointCapture(function () use ($bent): array {
    $path = new BattlePresentationPath($bent ? [[900, 300], [500, 100], [100, 300]] : [[900, 300], [900, 300]]);
    return [spl_object_id($this->actor) => ['point' => new Vector2(900, 300), 'casterX' => 900,
      'recipientX' => 100, 'party' => true, 'path' => $path, 'fallback' => null]];
  });
  $playback->update($playback->plan->getCommandFrameForAuthoredFrame('target', 6) / 120);
  expect(array_find(($this->compose)($playback)->images, static fn($image) => str_contains($image->id, 'target-beam')))->toBeNull()
    ->and($playback->presentationFailure)->toBeInstanceOf(InvalidArgumentException::class)->and($observed->hits)->toBe(1);
})->with([false, true]);
