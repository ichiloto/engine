<?php

use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneCompiler;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneKeyframe;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneTrack;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-summon-images-' . bin2hex(random_bytes(6));
  mkdir($this->root);
  writeTestPng($this->root . '/sheet.png', 16, 8);
  $this->source = ['fps' => 12, 'lengthFrames' => 4, 'restFrame' => 2, 'tracks' => [[
    'id' => 'arrival', 'type' => 'image', 'asset' => 'sheet.png', 'sheet' => ['columns' => 2, 'rows' => 1],
    'cells' => ['width' => 3, 'height' => 4], 'depth' => 'behind', 'anchor' => 'caster',
    'attachment' => 'ground', 'pivot' => ['x' => .2, 'y' => .8], 'facing' => 'west', 'presentation' => 'graphical',
    'keyframes' => [['frame' => 0, 'duration' => 2, 'sourceFrame' => 0],
      ['frame' => 2, 'duration' => 2, 'sourceFrame' => 1, 'flipX' => true, 'flipY' => false]],
  ], [
    'id' => 'terminal-impact', 'type' => 'glyph', 'presentation' => 'terminal',
    'keyframes' => [['frame' => 2, 'duration' => 2, 'content' => '*', 'position' => [3, 4]]],
  ]], 'cues' => [['id' => 'impact', 'frame' => 2, 'type' => 'applyEffect']]];
  $this->data = ['id' => 'synthetic', 'name' => 'Synthetic',
    'effectTiming' => ['mode' => 'cue', 'cueId' => 'impact']];
  $this->compiler = new SummonCutsceneCompiler(assetRoot: $this->root);
});

afterEach(function () {
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

it('preserves stage image offsets and opacity through typed read edit add and shared compilation', function (float $opacity) {
  $source = $this->source;
  $source['stage'] = ['canvas' => ['width' => 400, 'height' => 300], 'startFrame' => 0, 'restoreFrame' => 3,
    'camera' => [['id' => 'wide', 'frame' => 0, 'focus' => ['x' => 200, 'y' => 150], 'zoom' => 1]]];
  $source['tracks'] = [$source['tracks'][0]];
  $image = &$source['tracks'][0];
  unset($image['cells'], $image['facing'], $image['attachment']);
  $image['anchor'] = 'stage';
  $image['placement'] = ['position' => ['x' => 100, 'y' => 250], 'size' => ['width' => 80, 'height' => 160]];
  $image['keyframes'][1]['position'] = ['x' => 11.5, 'y' => -24.7];
  $image['keyframes'][1]['opacity'] = $opacity;
  $track = SummonCutsceneTrack::fromArray($image);
  $keyframe = $track->getKeyframes()[1];
  expect($keyframe->position)->toBe(['x' => 11.5, 'y' => -24.7])
    ->and($keyframe->opacity)->toBe($opacity)->and($keyframe->toImageArray())->toEqual($image['keyframes'][1]);
  $keyframe->position['x'] += .25;
  $edited = new SummonCutsceneTrack('image', 'edited', [$keyframe, $track->getKeyframes()[0]]);
  $source['tracks'][0]['keyframes'] = $edited->toArray()['keyframes'];
  $definition = SummonCutsceneDefinition::fromArrays([...$this->data,
    'transitionIn' => ['durationMs' => 0], 'transitionOut' => ['durationMs' => 0]], $source);
  $compiled = $this->compiler->compile($definition);
  expect($source['tracks'][0]['keyframes'][0]['frame'])->toBe(0)
    ->and($compiled->playbackSegments[1]['drawCommands'][0]['position'])->toBe(['x' => 11.75, 'y' => -24.7])
    ->and($compiled->playbackSegments[1]['drawCommands'][0]['payload']['opacity'])->toBe($opacity);
})->with([0.0, .45, 1.0]);

it('retains image fields when appending a typed keyframe without imposing them on terminal cells', function () {
  $track = SummonCutsceneTrack::fromArray($this->source['tracks'][0]);
  $keyframe = new SummonCutsceneKeyframe(frame: 4, position: ['x' => 1.5, 'y' => -.25],
    sourceFrame: 1, flipX: false, flipY: true, opacity: .25);
  $track->addKeyframe($keyframe);
  expect($track->toArray()['keyframes'][2])->toBe($keyframe->toImageArray())
    ->and($track->getKeyframes()[2]->position)->toBe(['x' => 1.5, 'y' => -.25])
    ->and($track->getKeyframes()[2]->opacity)->toBe(.25);
  $glyph = SummonCutsceneKeyframe::fromArray(['frame' => 0, 'position' => [3.75, 4.5]]);
  expect($glyph->position)->toBe(['x' => 3, 'y' => 4])->and($glyph->toArray())->not->toHaveKey('opacity');
});

it('round-trips and compiles summon image geometry through the shared effect contract', function () {
  $this->source['tracks'][0]['fit'] = 'contain';
  $definition = SummonCutsceneDefinition::fromArrays($this->data, $this->source);
  expect($definition->toTimelineArray()['tracks'][0])->toEqual($this->source['tracks'][0]);
  $effect = $this->compiler->compile($definition);
  $draw = $effect->playbackSegments[1]['drawCommands'][0];
  expect($draw['assetId'])->toBe('sheet.png')->and($draw['payload'])->toMatchArray([
    'sourceFrame' => 1, 'frameWidth' => 8, 'frameHeight' => 8, 'columns' => 2, 'rows' => 1,
    'cells' => ['width' => 3, 'height' => 4], 'fit' => 'contain', 'depth' => 'behind', 'anchor' => 'caster',
    'attachment' => 'ground', 'pivot' => ['x' => .2, 'y' => .8], 'facing' => 'west',
    'flipX' => true, 'flipY' => false,
  ])->and($effect->defaults['restFrame'])->toBe(2)->and($effect->defaults['lengthFrames'])->toBe(4);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.2, .2, .2, .2, .2, .2, .2), target: $effect);
  expect($plan->restSegments['target'][0]['drawCommands'][0]['payload']['anchor'])->toBe('caster');
});

it('does not make terminal summons depend on images and retains old screen coordinates', function () {
  unlink($this->root . '/sheet.png');
  $definition = SummonCutsceneDefinition::fromArrays($this->data, $this->source);
  $effect = $this->compiler->compile($definition, EffectPresentation::TERMINAL);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.2, .2, .2, .2, .2, .2, .2), target: $effect);
  expect($effect->playbackSegments)->toHaveCount(1)
    ->and($plan->timeline->playbackSegments[0]['drawCommands'][0]['payload']['anchor'])->toBe('legacy-screen')
    ->and($effect->playbackSegments[0]['drawCommands'][0]['position'])->toBe(['x' => 3, 'y' => 4]);
  expect(fn() => $this->compiler->compile($definition))->toThrow(RuntimeException::class);
});

it('preserves layered legacy summon glyphs without relaxing image crop validation', function () {
  $track = $this->source['tracks'][1];
  $track['keyframes'][] = $track['keyframes'][0];
  $definition = SummonCutsceneDefinition::fromArrays(['id' => 'layered', 'name' => 'Layered'],
    ['fps' => 12, 'lengthFrames' => 4, 'tracks' => [$track]]);
  $effect = $this->compiler->compile($definition, EffectPresentation::TERMINAL);
  expect($effect->playbackSegments)->toHaveCount(2)->and($effect->defaults['lengthFrames'])->toBe(4);
});

it('refuses empty or mixed summon presentation wrappers before losing their authored source', function () {
  expect(fn() => SummonCutsceneDefinition::fromArrays(['id' => 'empty'], ['presentations' => []]))
    ->toThrow(InvalidArgumentException::class);
});

it('keeps independently authored terminal and graphical sequences under one summon identity', function () {
  $terminal = ['fps' => 10, 'lengthFrames' => 5, 'restFrame' => 4, 'tracks' => [$this->source['tracks'][1]],
    'cues' => [['id' => 'impact', 'frame' => 4, 'type' => 'applyEffect']]];
  $definition = SummonCutsceneDefinition::fromArrays($this->data, [
    'formatVersion' => 1, 'presentations' => ['graphical' => $this->source, 'terminal' => $terminal],
  ]);
  $restored = SummonCutsceneDefinition::fromArrays($definition->toDataArray(), $definition->toTimelineArray());
  expect($restored->toTimelineArray())->toBe($definition->toTimelineArray())
    ->and($restored->toTimelineArray()['presentations']['terminal']['tracks'][0]['keyframes'][0]['position'])->toBe([3, 4]);
  $graphic = $this->compiler->compile($restored);
  $text = $this->compiler->compile($restored, EffectPresentation::TERMINAL);
  expect($graphic->sourceId)->toBe($text->sourceId)->and($graphic->fps)->toBe(12)->and($text->fps)->toBe(10)
    ->and($graphic->defaults['lengthFrames'])->toBe(4)->and($text->defaults['lengthFrames'])->toBe(5)
    ->and($text->defaults['restFrame'])->toBe(4)->and($text->cueSchedule[0]['frame'])->toBe(4)
    ->and($text->playbackSegments[0]['drawCommands'][0]['position'])->toBe(['x' => 3, 'y' => 4]);
});

it('rejects unsafe malformed or uncovered summon image tracks instead of dropping their fields', function (Closure $change) {
  $change($this->source);
  expect(fn() => $this->compiler->compile(SummonCutsceneDefinition::fromArrays($this->data, $this->source)))
    ->toThrow(InvalidArgumentException::class);
})->with([
  'unsafe path' => [static function (&$source) { $source['tracks'][0]['asset'] = '../sheet.png'; }],
  'bad grid' => [static function (&$source) { $source['tracks'][0]['sheet']['columns'] = 3; }],
  'out of atlas' => [static function (&$source) { $source['tracks'][0]['keyframes'][1]['sourceFrame'] = 2; }],
  'negative frame' => [static function (&$source) { $source['tracks'][0]['keyframes'][0]['frame'] = -1; }],
  'overlap' => [static function (&$source) { $source['tracks'][0]['keyframes'][1]['frame'] = 1; }],
  'uncovered rest' => [static function (&$source) { $source['restFrame'] = 3; $source['tracks'][0]['keyframes'][1]['duration'] = 1; }],
  'screen attachment' => [static function (&$source) { $source['tracks'][0]['anchor'] = 'screen'; }],
  'invalid pivot' => [static function (&$source) { $source['tracks'][0]['pivot']['y'] = 2; }],
  'numeric flip' => [static function (&$source) { $source['tracks'][0]['keyframes'][1]['flipX'] = 1; }],
  'unknown fit' => [static function (&$source) { $source['tracks'][0]['fit'] = 'cover'; }],
  'null fit' => [static function (&$source) { $source['tracks'][0]['fit'] = null; }],
  'unknown field' => [static function (&$source) { $source['tracks'][0]['mystery'] = true; }],
]);

it('revalidates replaced art and separates per-battle compiled renderer caches', function () {
  $directory = $this->root . '/Summons/synthetic';
  mkdir($directory, 0777, true);
  file_put_contents($directory . '/synthetic.data.php', '<?php return ' . var_export($this->data, true) . ';');
  file_put_contents($directory . '/synthetic.timeline.php', '<?php return ' . var_export($this->source, true) . ';');
  $library = new SummonCutsceneLibrary($this->root . '/Summons', cacheForBattle: true, assetRoot: $this->root);
  $first = $library->loadCompiledOrCompile('synthetic');
  file_put_contents($directory . '/synthetic.compiled.php', '<?php return ' . var_export($first->toArray(), true) . ';');
  writeTestPng($this->root . '/sheet.png', 32, 8);
  $next = new SummonCutsceneLibrary($this->root . '/Summons', cacheForBattle: true, assetRoot: $this->root);
  expect($next->loadCompiledOrCompile('synthetic')->playbackSegments[0]['drawCommands'][0]['payload']['frameWidth'])->toBe(16)
    ->and($library->loadCompiledOrCompile('synthetic'))->toBe($first)
    ->and($library->loadCompiledOrCompile('synthetic', EffectPresentation::TERMINAL)->playbackSegments)->toHaveCount(1);
});
