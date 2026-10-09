<?php

use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStage;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStagePresentation;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneCompiler;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-stage-' . bin2hex(random_bytes(6));
  mkdir($this->root);
  writeTestPng($this->root . '/body.png', 8, 16);
  writeTestPng($this->root . '/core.png', 8, 8);
  $this->stage = [
    'canvas' => ['width' => 400, 'height' => 300], 'startFrame' => 1, 'restoreFrame' => 9,
    'subjects' => [['id' => 'visitor', 'position' => ['x' => 250, 'y' => 260],
      'size' => ['width' => 80, 'height' => 160],
      'attachments' => [['id' => 'ground', 'x' => .5, 'y' => 1], ['id' => 'chest', 'x' => .5, 'y' => .375]]]],
    'camera' => [['id' => 'initial', 'frame' => 0, 'focus' => ['x' => 200, 'y' => 150], 'zoom' => 1, 'easing' => 'hold'],
      ['id' => 'reveal-start', 'frame' => 4, 'focus' => ['x' => 200, 'y' => 150], 'zoom' => 1],
      ['id' => 'reveal-end', 'frame' => 8, 'focus' => ['x' => 200, 'y' => 150], 'zoom' => .5]],
    'covers' => [['id' => 'initial', 'frame' => 0, 'color' => 'black', 'opacity' => 0, 'easing' => 'hold'],
      ['id' => 'white-start', 'frame' => 7, 'color' => 'white', 'opacity' => 0],
      ['id' => 'whiteout', 'frame' => 8, 'color' => 'white', 'opacity' => 1],
      ['id' => 'clear', 'frame' => 11, 'color' => 'white', 'opacity' => 0]],
  ];
  $this->source = ['fps' => 12, 'lengthFrames' => 12, 'restFrame' => 3, 'stage' => $this->stage,
    'tracks' => [['id' => 'body', 'type' => 'image', 'asset' => 'body.png', 'anchor' => 'stage',
      'placement' => ['subject' => 'visitor', 'attachment' => 'ground'],
      'pivot' => ['x' => .5, 'y' => 1], 'zIndex' => 0,
      'keyframes' => [['frame' => 1, 'duration' => 8]]],
      ['id' => 'core', 'type' => 'image', 'asset' => 'core.png', 'anchor' => 'stage',
        'placement' => ['subject' => 'visitor', 'attachment' => 'chest', 'size' => ['width' => 16, 'height' => 16]],
        'zIndex' => 10, 'keyframes' => [['frame' => 1, 'duration' => 8, 'opacity' => .5]]]],
    'cues' => [['id' => 'impact', 'type' => 'applyEffect', 'frame' => 4]]];
  $this->data = ['id' => 'synthetic-stage', 'name' => 'Synthetic', 'effectTiming' => ['mode' => 'cue', 'cueId' => 'impact']];
  $this->compiler = new SummonCutsceneCompiler(assetRoot: $this->root);
});

afterEach(function () {
  foreach (glob($this->root . '/*') as $file) { unlink($file); }
  rmdir($this->root);
});

it('accepts each shared authoring easing for camera and cover keys', function (string $easing) {
  $this->stage['camera'][0]['easing'] = $easing;
  $this->stage['covers'][0]['easing'] = $easing;
  $stage = CinematicStage::fromArray($this->stage, 12, 3);
  expect($stage->data['camera'][0]['easing'])->toBe($easing)
    ->and($stage->data['covers'][0]['easing'])->toBe($easing);
})->with(CinematicStage::EASINGS);

it('preserves source stage identities and rehydrates validated compiled caches', function () {
  $definition = SummonCutsceneDefinition::fromArrays($this->data, $this->source);
  expect($definition->toTimelineArray()['stage'])->toBe($this->stage)
    ->and($definition->toTimelineArray()['tracks'])->toEqual($this->source['tracks']);
  $compiled = $this->compiler->compile($definition);
  $cached = SummonCompiledCutscene::fromArray($compiled->toArray());
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $cached);
  expect($plan->cinematicStage->data['subjects'][0]['id'])->toBe('visitor')
    ->and($plan->phases)->not->toHaveKeys(['summon-in', 'summon-title', 'summon-out']);
  $frame = $plan->getCommandFrameForAuthoredFrame('target', 6);
  expect($plan->getCinematicStageFrame($frame)->contentFrame)->toBe(6)
    ->and($plan->getCinematicStageFrame($plan->phases['reaction']['start']))->toBeNull();
});

it('applies one camera transform to body geometry and named chest attachments', function () {
  $compiled = $this->compiler->compile(SummonCutsceneDefinition::fromArrays($this->data, $this->source));
  $stage = CinematicStage::fromArray($compiled->defaults['stage'], 12, 3);
  $canvas = CinematicStagePresentation::compose($stage->getFrame(6), $compiled->playbackSegments, $this->root, 400, 300);
  [$body, $core] = $canvas->images;
  expect($body->destination->toArray())->toBe(['x' => 207.5, 'y' => 112.5, 'width' => 60.0, 'height' => 120.0])
    ->and($core->destination->toArray())->toBe(['x' => 231.5, 'y' => 151.5, 'width' => 12.0, 'height' => 12.0])
    ->and($core->opacity)->toBe(.5)->and($core->layer)->toBeGreaterThan($body->layer)
    ->and($canvas->composites[0]->width)->toBe(1);
});

it('restores the battlefield before fading its white cover completely clear', function () {
  $compiled = $this->compiler->compile(SummonCutsceneDefinition::fromArrays($this->data, $this->source));
  $stage = CinematicStage::fromArray($compiled->defaults['stage'], 12, 3);
  $covered = CinematicStagePresentation::compose($stage->getFrame(9), $compiled->playbackSegments, $this->root, 400, 300);
  $clear = CinematicStagePresentation::compose($stage->getFrame(11), $compiled->playbackSegments, $this->root, 400, 300);
  expect($covered->images)->toBeEmpty()->and($covered->composites)->toHaveCount(1)
    ->and($covered->composites[0]->operations[0]->data['brush']['color'])->toBe(['kind' => 'rgb', 'r' => 255, 'g' => 255, 'b' => 255])
    ->and($covered->composites[0]->opacity)->toEqualWithDelta(2 / 3, .00001)
    ->and($covered->composites[0]->layer)->toBeLessThan(PresentationLayerPolicy::NOTIFICATIONS)
    ->and($clear->images)->toBeEmpty()->and($clear->composites)->toBeEmpty();
});

it('composes fractional zoomed image edges under strict canvas bounds in normal and reduced motion', function () {
  writeTestPng($this->root . '/body.png', 2048, 1536);
  $this->source['stage']['canvas'] = ['width' => 1920, 'height' => 1080];
  $this->source['stage']['subjects'][0] = ['id' => 'visitor', 'position' => ['x' => 0, 'y' => 0],
    'size' => ['width' => 1638.4, 'height' => 1228.8000000000002], 'attachments' => []];
  $this->source['stage']['camera'] = [['id' => 'zoom', 'frame' => 0,
    'focus' => ['x' => 744.8000000000002, 'y' => 434.4000000000001], 'zoom' => 1.5, 'easing' => 'hold']];
  $this->source['tracks'] = [$this->source['tracks'][0]];
  $this->source['tracks'][0]['placement'] = ['subject' => 'visitor'];
  $this->source['tracks'][0]['pivot'] = ['x' => 0, 'y' => 0];
  $compiled = $this->compiler->compile(SummonCutsceneDefinition::fromArrays($this->data, $this->source));
  $stage = CinematicStage::fromArray($compiled->defaults['stage'], 12, 3);
  foreach ([false, true] as $reduced) {
    for ($frame = 0; $frame < 12; $frame++) {
      $canvas = CinematicStagePresentation::compose($stage->getFrame($frame, $reduced),
        $compiled->playbackSegments, $this->root, 1280, 720);
      foreach ($canvas->images as $image) {
        expect($image->destination->x + $image->destination->width)->toBeLessThanOrEqual(1280)
          ->and($image->destination->y + $image->destination->height)->toBeLessThanOrEqual(720)
          ->and($image->sourceRect->width)->toBe(1599)->and($image->sourceRect->height)->toBe(899);
      }
      if ($frame === 3) {
        expect($canvas->images)->toHaveCount(1)
          ->and($canvas->images[0]->destination->y)->toBe(0.7999999999999261);
      }
    }
  }
});

it('holds the authored reduced-motion view while preserving restoration boundaries', function () {
  $stage = CinematicStage::fromArray($this->stage, 12, 3);
  expect($stage->getFrame(8, true)->contentFrame)->toBe(3)
    ->and($stage->getFrame(8, true)->camera)->toBe($stage->getFrame(3)->camera)
    ->and($stage->getFrame(8, true)->cover['opacity'])->toBe(0.0)
    ->and($stage->getFrame(9, true)->active)->toBeFalse();
});

it('keeps foreground tracks over the restored arena without retaining the cinematic backdrop', function () {
  $this->source['tracks'][0]['keyframes'][0]['duration'] = 11;
  $this->source['stage']['covers'] = [];
  $compiled = $this->compiler->compile(SummonCutsceneDefinition::fromArrays($this->data, $this->source));
  $stage = CinematicStage::fromArray($compiled->defaults['stage'], 12, 3);
  $frame = $stage->getFrame(9);
  $canvas = CinematicStagePresentation::compose($frame, $compiled->playbackSegments, $this->root, 400, 300);
  expect($frame->active)->toBeFalse()->and($frame->drawsContent)->toBeTrue()
    ->and($canvas->images)->toHaveCount(1)->and($canvas->images[0]->id)->toBe('cinematic-stage-body')
    ->and($canvas->composites)->toBeEmpty();
  $reduced = CinematicStagePresentation::compose($stage->getFrame(9, true), $compiled->playbackSegments, $this->root, 400, 300);
  $ended = CinematicStagePresentation::compose($stage->getFrame(12), $compiled->playbackSegments, $this->root, 400, 300);
  expect($reduced->images)->toBeEmpty()->and($ended->images)->toBeEmpty();
});

it('does not acquire unselected graphical stages or images for terminal summons', function () {
  $terminal = ['fps' => 12, 'lengthFrames' => 12, 'tracks' => [], 'cues' => $this->source['cues']];
  $graphical = $this->source;
  $graphical['stage']['camera'][0]['zoom'] = 'invalid';
  $graphical['tracks'][0]['keyframes'] = null;
  unlink($this->root . '/body.png');
  $definition = SummonCutsceneDefinition::fromArrays($this->data,
    ['presentations' => ['terminal' => $terminal, 'graphical' => $graphical]]);
  $compiled = $this->compiler->compile($definition, EffectPresentation::TERMINAL);
  expect($compiled->defaults)->not->toHaveKey('stage')->and($compiled->playbackSegments)->toBeEmpty()
    ->and(fn() => $this->compiler->compile($definition))->toThrow(InvalidArgumentException::class);
});

it('reads replaced images without pinning dimensions into the stage identity', function () {
  $compiled = $this->compiler->compile(SummonCutsceneDefinition::fromArrays($this->data, $this->source));
  $stage = CinematicStage::fromArray($compiled->defaults['stage'], 12, 3);
  $first = CinematicStagePresentation::compose($stage->getFrame(3), $compiled->playbackSegments, $this->root, 400, 300);
  writeTestPng($this->root . '/body.png', 16, 32);
  $next = CinematicStagePresentation::compose($stage->getFrame(3), $compiled->playbackSegments, $this->root, 400, 300);
  expect($next->images[0]->destination->toArray())->toBe($first->images[0]->destination->toArray())
    ->and($next->images[0]->sourceRect->width)->toBe(16)->and($next->images[0]->sourceRect->height)->toBe(32);
});

it('refuses unsupported renderer capabilities rather than claiming a stage was shown', function () {
  $compiled = $this->compiler->compile(SummonCutsceneDefinition::fromArrays($this->data, $this->source));
  $stage = CinematicStage::fromArray($compiled->defaults['stage'], 12, 3);
  expect(fn() => CinematicStagePresentation::compose($stage->getFrame(3), $compiled->playbackSegments,
    $this->root, 400, 300, compositing: false))->toThrow(InvalidArgumentException::class);
});

it('rejects ambiguous or unsafe stage contracts before runtime', function (Closure $change) {
  $change($this->source);
  expect(fn() => $this->compiler->compile(SummonCutsceneDefinition::fromArrays($this->data, $this->source)))
    ->toThrow(InvalidArgumentException::class);
})->with([
  'unknown stage field' => [static function (&$s) { $s['stage']['video'] = 'movie.mp4'; }],
  'bad canvas' => [static function (&$s) { $s['stage']['canvas']['width'] = 0; }],
  'negative start' => [static function (&$s) { $s['stage']['startFrame'] = -1; }],
  'missing restore' => [static function (&$s) { unset($s['stage']['restoreFrame']); }],
  'late restore' => [static function (&$s) { $s['stage']['restoreFrame'] = 12; }],
  'empty interval' => [static function (&$s) { $s['stage']['restoreFrame'] = 1; }],
  'unsafe rest' => [static function (&$s) { $s['restFrame'] = 10; }],
  'uncovered safe pose' => [static function (&$s) { $s['restFrame'] = 0; $s['stage']['startFrame'] = 0; }],
  'duplicate subjects' => [static function (&$s) { $s['stage']['subjects'][] = $s['stage']['subjects'][0]; }],
  'unknown attachment' => [static function (&$s) { $s['tracks'][1]['placement']['attachment'] = 'missing'; }],
  'unknown subject' => [static function (&$s) { $s['tracks'][1]['placement']['subject'] = 'missing'; }],
  'no subject for attachment' => [static function (&$s) { unset($s['tracks'][1]['placement']['subject']); }],
  'mixed cells' => [static function (&$s) { $s['tracks'][0]['cells'] = ['width' => 3, 'height' => 2]; }],
  'mixed battler attachment' => [static function (&$s) { $s['tracks'][0]['attachment'] = 'ground'; }],
  'nonfinite point' => [static function (&$s) { $s['stage']['subjects'][0]['position']['x'] = INF; }],
  'missing camera' => [static function (&$s) { $s['stage']['camera'] = []; }],
  'unordered camera' => [static function (&$s) { $s['stage']['camera'][1]['frame'] = 0; }],
  'excessive zoom' => [static function (&$s) { $s['stage']['camera'][0]['zoom'] = 5; }],
  'unknown easing' => [static function (&$s) { $s['stage']['camera'][0]['easing'] = 'bounce'; }],
  'uncleared cover' => [static function (&$s) { $s['stage']['covers'][3]['opacity'] = 1; }],
  'bad cover color' => [static function (&$s) { $s['stage']['covers'][0]['color'] = '#notacolor'; }],
  'unsafe image opacity' => [static function (&$s) { $s['tracks'][0]['keyframes'][0]['opacity'] = 2; }],
  'image above notifications' => [static function (&$s) { $s['tracks'][0]['zIndex'] = 2000; }],
  'no stage for placement' => [static function (&$s) { unset($s['stage']); }],
  'placement on battler' => [static function (&$s) { $s['tracks'][0]['anchor'] = 'caster'; }],
  'null subjects' => [static function (&$s) { $s['stage']['subjects'] = null; }],
  'null covers' => [static function (&$s) { $s['stage']['covers'] = null; }],
  'null pivot' => [static function (&$s) { $s['stage']['subjects'][0]['pivot'] = null; }],
  'null attachments' => [static function (&$s) { $s['stage']['subjects'][0]['attachments'] = null; }],
  'null placement subject' => [static function (&$s) { $s['tracks'][0]['placement']['subject'] = null; }],
  'null placement attachment' => [static function (&$s) { $s['tracks'][0]['placement']['attachment'] = null; }],
  'null placement size' => [static function (&$s) { $s['tracks'][0]['placement']['size'] = null; }],
  'null image opacity' => [static function (&$s) { $s['tracks'][0]['keyframes'][0]['opacity'] = null; }],
  'null image layer' => [static function (&$s) { $s['tracks'][0]['zIndex'] = null; }],
  'null forbidden cells' => [static function (&$s) { $s['tracks'][0]['cells'] = null; }],
]);

it('identifies invalid fields by stable authoring row and coordinate', function (Closure $change, string $path) {
  $change($this->source);
  expect(fn() => $this->compiler->compile(SummonCutsceneDefinition::fromArrays($this->data, $this->source)))
    ->toThrow(InvalidArgumentException::class, $path);
})->with([
  [static function (&$s) { $s['stage']['camera'][0]['zoom'] = 0; }, 'stage.camera[initial].zoom'],
  [static function (&$s) { $s['stage']['camera'][0]['focus']['x'] = INF; }, 'stage.camera[initial].focus.x'],
  [static function (&$s) { $s['stage']['covers'][0]['opacity'] = 2; }, 'stage.covers[initial].opacity'],
  [static function (&$s) { $s['stage']['subjects'][0]['attachments'][1]['x'] = 2; }, 'stage.subjects[visitor].attachments[chest].x'],
  [static function (&$s) { $s['tracks'][0]['placement']['subject'] = 'missing'; }, 'tracks[body].placement'],
  [static function (&$s) { $s['tracks'][0]['keyframes'][0]['opacity'] = 2; }, 'tracks[body].keyframes[0].opacity'],
]);

it('defaults only absent optional stage fields and preserves authored zero coordinates', function () {
  unset($this->stage['covers'], $this->stage['subjects'][0]['attachments']);
  $this->stage['subjects'][0]['pivot'] = ['x' => 0, 'y' => 0];
  $stage = CinematicStage::fromArray($this->stage, 12, 3);
  $placement = CinematicStage::validatePlacement(['subject' => 'visitor'], $stage);
  expect($stage->data['background'])->toBe('black')->and($stage->data['covers'])->toBe([])
    ->and($stage->data['subjects'][0]['pivot'])->toBe(['x' => 0.0, 'y' => 0.0])
    ->and($stage->data['subjects'][0]['attachments'])->toBe([])
    ->and($placement['position'])->toBe(['x' => 0.0, 'y' => 0.0])
    ->and($placement['size'])->toBe(['width' => 80.0, 'height' => 160.0]);
});
