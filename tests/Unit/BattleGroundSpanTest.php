<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\{BattleEffectAnchorSnapshot, BattleEffectTargetGroup, GraphicalBattleGroundSpan, GraphicalBattleStrip, BattleCanvasLayout};
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\{Character, Stats};
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasRectangle, CanvasTextureStrip};
use Ichiloto\Engine\Rendering\Presentation\{PresentationSpritePivot, SpriteSourceRect};
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use function Tests\Support\Rendering\writeTestPng;
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

function groundSpanSnapshot(array $points, Vector2 $caster): BattleEffectAnchorSnapshot
{
  $targets = $bounds = $grounds = [];
  foreach ($points as [$x, $y]) {
    $target = new Character('Same name', 1, new Stats(currentHp: 100, totalHp: 100));
    $targets[] = $target; $id = spl_object_id($target);
    $bounds[$id] = new CanvasRectangle(0, 0, 20, 30); $grounds[$id] = new Vector2($x, $y);
  }
  return new BattleEffectAnchorSnapshot(BattleEffectTargetGroup::collectTargets($targets, $bounds, $grounds), $caster);
}

function groundSpanSource(): array
{
  return ['fps' => 24, 'lengthFrames' => 12, 'targetCaptureFrame' => 2, 'restFrame' => 3,
    'tracks' => [['id' => 'ground', 'type' => 'image', 'asset' => 'span.png', 'presentation' => 'graphical',
      'anchor' => 'target-group', 'attachment' => 'ground', 'anchorLifetime' => 'target-capture', 'fit' => 'contain',
      'pivot' => ['x' => .9, 'y' => .5], 'groundSpan' => ['tip' => ['x' => .1, 'y' => .5]],
      'keyframes' => [['frame' => 3, 'duration' => 8]]]]];
}

it('uses the farthest selected X at the captured group depth independently of member order', function (bool $reverse) {
  $points = [[-50.0, 20.0], [50.0, 30.0], [80.0, 40.0]];
  $snapshot = groundSpanSnapshot($reverse ? array_reverse($points) : $points, new Vector2(0, 80));
  expect($snapshot->getGroundSpanEndpoint())->toEqual(new Vector2(80, 40));
})->with([false, true]);

it('keeps a single selected endpoint at its actual held ground point', function () {
  $snapshot = groundSpanSnapshot([[30.25, 40.75]], new Vector2(90.5, 80.125));
  expect($snapshot->getGroundSpanEndpoint())->toEqual(new Vector2(30.25, 40.75));
});

it('refuses opposite tied far endpoints instead of choosing a source-order direction', function () {
  $snapshot = groundSpanSnapshot([[-50, 20], [50, 30]], new Vector2(0, 80));
  expect(fn() => $snapshot->getGroundSpanEndpoint())->toThrow(InvalidArgumentException::class, 'equally far');
});

it('maps source rear and tip exactly with uniform scale for fractional rays and explicit flips', function (bool $flipX, bool $flipY, array $tip, bool $fromLeft) {
  $snapshot = groundSpanSnapshot([[31.25, 47.125], [62.75, 83.375]], new Vector2($fromLeft ? -1000.625 : 1000.625, 501.875));
  $crop = new SpriteSourceRect(20, 30, 160, 32); $rear = new PresentationSpritePivot(.9, .6);
  $strip = GraphicalBattleGroundSpan::createStrip($snapshot, $crop, ['tip' => $tip], $rear, $flipX, $flipY);
  $start = $snapshot->getCasterGround(); $end = $snapshot->getGroundSpanEndpoint();
  [$u, $v] = $strip->getSourcePoint($start->x, $start->y);
  [$tu, $tv] = $strip->getSourcePoint($end->x, $end->y);
  expect($u)->toEqualWithDelta($flipX ? .1 : .9, 1e-12)
    ->and($v)->toEqualWithDelta($flipY ? .4 : .6, 1e-12)
    ->and($tu)->toEqualWithDelta($flipX ? 1 - $tip['x'] : $tip['x'], 1e-12)
    ->and($tv)->toEqualWithDelta($flipY ? 1 - $tip['y'] : $tip['y'], 1e-12)
    ->and($strip->length / 160)->toEqualWithDelta($strip->height / 32, 1e-12)
    ->and($strip->knots)->toBe([['u' => 0.0, 'offset' => 0.0], ['u' => 1.0, 'offset' => 0.0]]);
})->with([false, true], [false, true], [[['x' => .1, 'y' => .6]], [['x' => .2, 'y' => .3]]], [false, true]);

it('clips the rigid image through the shared strip compositor while retaining the source mapping', function () {
  $snapshot = groundSpanSnapshot([[30.25, 50.75]], new Vector2(2000.5, 400.125));
  $crop = new SpriteSourceRect(0, 0, 160, 32); $rear = new PresentationSpritePivot(.9, .5);
  $strip = GraphicalBattleGroundSpan::createStrip($snapshot, $crop, ['tip' => ['x' => .1, 'y' => .5]], $rear);
  $canvas = GraphicalBattleStrip::composeStrip('span', 'span.png', $crop, $strip, new BattleCanvasLayout(1350, 720), ['depth' => 'behind']);
  expect($canvas)->not->toBeNull()->and($canvas->layer)->toBe(90)
    ->and($canvas->destination->x)->toBeGreaterThanOrEqual(0)->and($canvas->destination->y)->toBeGreaterThanOrEqual(0)
    ->and($canvas->destination->x + $canvas->destination->width)->toBeLessThanOrEqual(1350)
    ->and($canvas->operations[0]->data['sourceRect'])->toBe($crop->toArray());
  $local = $canvas->operations[0]->textureStrip;
  $point = $snapshot->getGroundSpanEndpoint();
  [$u, $v] = $local->getSourcePoint($point->x - $canvas->destination->x, $point->y - $canvas->destination->y);
  expect($u)->toEqualWithDelta(.1, 1e-12)->and($v)->toEqualWithDelta(.5, 1e-12);
});

it('refuses zero source or world reach and nonfinite target coordinates', function (string $kind) {
  $snapshot = groundSpanSnapshot($kind === 'nonfinite' ? [[INF, 30]] : [[50, 30]], new Vector2($kind === 'world' ? 50 : 100, 30));
  $tip = $kind === 'source' ? ['x' => .9, 'y' => .5] : ['x' => .1, 'y' => .5];
  expect(fn() => GraphicalBattleGroundSpan::createStrip($snapshot, new SpriteSourceRect(0, 0, 160, 32),
    ['tip' => $tip], new PresentationSpritePivot(.9, .5)))->toThrow(InvalidArgumentException::class);
})->with(['source', 'world', 'nonfinite']);

describe('ground-span source compilation', function () {
  beforeEach(function () {
    $this->spanRoot = getTestTemporaryDirectory() . '/ground-span-' . bin2hex(random_bytes(4)); mkdir($this->spanRoot);
    writeTestPng($this->spanRoot . '/span.png', 160, 32); $this->spanLibrary = new EffectTimelineLibrary($this->spanRoot);
  });
  afterEach(function () { unlink($this->spanRoot . '/span.png'); rmdir($this->spanRoot); });

  it('compiles the measured source points and independent capture lifetime without changing the source', function () {
    $source = groundSpanSource(); $before = serialize($source);
    $effect = $this->spanLibrary->compile('span', $source, true);
    $payload = $effect->playbackSegments[0]['drawCommands'][0]['payload'];
    expect($payload['groundSpan'])->toBe(['tip' => ['x' => .1, 'y' => .5]])
      ->and($payload['anchorLifetime'])->toBe('target-capture')->and(serialize($source))->toBe($before);
    $copy = \Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline::fromArray($effect->toArray());
    expect($copy->toArray())->toBe($effect->toArray());
  });

  it('preserves the ground-span descriptor through the actual summon authoring and compilation boundaries', function (bool $paired) {
    $graphical = groundSpanSource();
    $terminal = ['fps' => 24, 'lengthFrames' => 12, 'restFrame' => 3,
      'tracks' => [['id' => 'text', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'duration' => 12, 'content' => '*']]]]];
    $source = $paired ? ['presentations' => ['graphical' => $graphical, 'terminal' => $terminal]] : $graphical;
    $definition = \Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition::fromArrays(['id' => 'span', 'name' => 'Synthetic'], $source);
    $export = $definition->toTimelineArray();
    $exported = $paired ? $export['presentations']['graphical'] : $export;
    expect($exported['tracks'][0]['groundSpan'])->toBe($graphical['tracks'][0]['groundSpan'])
      ->and($exported['tracks'][0]['pivot'])->toBe($graphical['tracks'][0]['pivot'])
      ->and($exported['targetCaptureFrame'])->toBe(2);
    $compiled = (new \Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneCompiler(assetRoot: $this->spanRoot))->compile($definition);
    expect($compiled->playbackSegments[0]['drawCommands'][0]['payload']['groundSpan'])
      ->toBe(['tip' => ['x' => .1, 'y' => .5]]);
    if ($paired) {
      expect($export['presentations']['terminal'])->toBe($terminal);
    }
  })->with([false, true]);

  it('refuses ambiguous ownership and per-frame endpoint shifts before compilation', function (array $change) {
    $source = groundSpanSource(); $source['tracks'][0] = array_replace($source['tracks'][0], $change);
    expect(fn() => $this->spanLibrary->compile('invalid', $source, true))->toThrow(InvalidArgumentException::class);
  })->with([
    [['anchor' => 'caster']], [['anchorLifetime' => 'effect-start']], [['fit' => 'stretch']], [['span' => true]],
    [['cells' => ['width' => 3, 'height' => 2]]], [['pivot' => null]], [['groundSpan' => ['tip' => ['x' => .9, 'y' => .5]]]],
    [['groundSpan' => ['tip' => ['x' => 2, 'y' => .5]]]], [['facing' => 'west']],
    [['keyframes' => [['frame' => 3, 'duration' => 8, 'position' => ['x' => 1, 'y' => 0]]]]],
  ]);
});


it('exports all owned ground-span fields and keeps omitted orientation byte-compatible', function (): void {
  $schema = \Ichiloto\Engine\Animations\Timelines\EffectGroundSpan::exportContract();
  expect($schema['field'])->toBe('groundSpan')->and($schema['tipField'])->toBe('tip')
    ->and($schema['captureField'])->toBe('targetCaptureFrame')
    ->and($schema['conflictingTrackFields'])->toBe(\Ichiloto\Engine\Animations\Timelines\EffectGroundSpan::CONFLICTING_TRACK_FIELDS)
    ->and($schema['orientations'])->toBe(['rigid', 'upright']);
  $source = ['tip' => ['x' => .1, 'y' => .5]];
  expect(\Ichiloto\Engine\Animations\Timelines\EffectGroundSpan::fromArray($source)->toArray())->toBe($source);
});

it('reports each actual ground-span ownership conflict by name before generic image validation', function (string $field): void {
  $source = groundSpanSource(); $track = $source['tracks'][0]; $track[$field] = 'conflict';
  expect(fn() => \Ichiloto\Engine\Animations\Timelines\EffectGroundSpan::fromTrack($track, true, 'once', $source, 'tracks[ground]'))
    ->toThrow(InvalidArgumentException::class, 'conflicting track fields: ' . $field);
})->with(\Ichiloto\Engine\Animations\Timelines\EffectGroundSpan::CONFLICTING_TRACK_FIELDS);

it('keeps one source upright on both casting sides with exact endpoints and uniform rigid geometry', function (bool $left, bool $flipX, bool $flipY, array $tip): void {
  $snapshot = groundSpanSnapshot([[200, 50]], new Vector2($left ? 20 : 380, 100));
  $rear = new PresentationSpritePivot(.9, .6); $crop = new SpriteSourceRect(3, 5, 160, 32);
  $projection = GraphicalBattleGroundSpan::createProjection($snapshot, $crop,
    ['tip' => $tip, 'orientation' => 'upright'], $rear, $flipX, $flipY);
  $strip = $projection['strip']; $start = $snapshot->getCasterGround(); $end = $snapshot->getGroundSpanEndpoint();
  [$u, $v] = $strip->getSourcePoint($start->x, $start->y); [$tu, $tv] = $strip->getSourcePoint($end->x, $end->y);
  expect($strip->axis[0])->toBeGreaterThan(0)
    ->and($strip->length / $crop->width)->toEqualWithDelta($strip->height / $crop->height, 1e-12)
    ->and($u)->toEqualWithDelta($projection['flipX'] ? 1 - $rear->x : $rear->x, 1e-12)
    ->and($v)->toEqualWithDelta($flipY ? 1 - $rear->y : $rear->y, 1e-12)
    ->and($tu)->toEqualWithDelta($projection['flipX'] ? 1 - $tip['x'] : $tip['x'], 1e-12)
    ->and($tv)->toEqualWithDelta($flipY ? 1 - $tip['y'] : $tip['y'], 1e-12)
    ->and($projection['flipY'])->toBe($flipY)
    ->and($strip->knots)->toBe([['u' => 0.0, 'offset' => 0.0], ['u' => 1.0, 'offset' => 0.0]]);
  $composite = GraphicalBattleGroundSpan::compose('span', 'source.png', $crop, $snapshot,
    new BattleCanvasLayout(400, 300, uiCellWidth: 2, uiCellHeight: 4), ['groundSpan' => ['tip' => $tip, 'orientation' => 'upright'],
      'pivot' => $rear->toArray(), 'depth' => 'behind', 'flipX' => $flipX, 'flipY' => $flipY]);
  expect($composite->operations[0]->data['flipX'])->toBe($projection['flipX'])
    ->and($composite->operations[0]->data['flipY'])->toBe($flipY);
})->with([false, true], [false, true], [false, true], [[['x' => .1, 'y' => .6]], [['x' => .2, 'y' => .3]]]);

it('diagnoses upright geometry that cannot keep the source top above its bottom', function (): void {
  $snapshot = groundSpanSnapshot([[100, 20]], new Vector2(100, 120));
  expect(fn() => GraphicalBattleGroundSpan::createProjection($snapshot, new SpriteSourceRect(0, 0, 160, 32),
    ['tip' => ['x' => .1, 'y' => .5], 'orientation' => 'upright'], new PresentationSpritePivot(.9, .5)))
    ->toThrow(InvalidArgumentException::class, 'cannot keep this source top');
  expect(GraphicalBattleGroundSpan::createStrip($snapshot, new SpriteSourceRect(0, 0, 160, 32),
    ['tip' => ['x' => .1, 'y' => .5]], new PresentationSpritePivot(.9, .5)))->toBeInstanceOf(CanvasTextureStrip::class);
});


it('keeps explicit upright source ownership through compilation and authored round trips', function (): void {
  $source = groundSpanSource(); $source['tracks'][0]['groundSpan']['orientation'] = 'upright';
  $root = createTestDirectory('upright-span-'); writeTestPng($root . '/span.png', 160, 32);
  $effect = (new EffectTimelineLibrary($root))->compile('upright', $source, true);
  expect($effect->playbackSegments[0]['drawCommands'][0]['payload']['groundSpan'])
    ->toBe(['tip' => ['x' => .1, 'y' => .5], 'orientation' => 'upright']);
  $definition = \Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition::fromArrays(['id' => 'upright', 'name' => 'Synthetic'], $source);
  expect($definition->toTimelineArray()['tracks'][0]['groundSpan'])->toBe($source['tracks'][0]['groundSpan']);
});

it('refuses malformed ground-span orientation before rendering', function (mixed $orientation): void {
  expect(fn() => \Ichiloto\Engine\Animations\Timelines\EffectGroundSpan::fromArray([
    'tip' => ['x' => .1, 'y' => .5], 'orientation' => $orientation]))
    ->toThrow(InvalidArgumentException::class, 'groundSpan.orientation');
})->with(['null' => [null], 'unknown' => ['invented'], 'bool' => [true], 'array' => [[]]]);
