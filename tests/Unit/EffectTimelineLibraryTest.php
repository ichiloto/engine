<?php

use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineCompiler;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneTrack;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->effectRoot = sys_get_temp_dir() . '/ichiloto-effect-contract-' . bin2hex(random_bytes(6));
  mkdir($this->effectRoot);
  writeTestPng($this->effectRoot . '/sheet.png', 16, 8);
  $this->effects = new EffectTimelineLibrary($this->effectRoot);
  $this->imageSequence = ['fps' => 12, 'lengthFrames' => 2, 'restFrame' => 1, 'tracks' => [[
    'id' => 'image', 'type' => 'image', 'asset' => 'sheet.png', 'sheet' => ['columns' => 2, 'rows' => 1],
    'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]],
  ]]];
  $this->terminalSequence = ['fps' => 10, 'lengthFrames' => 6, 'restFrame' => 4, 'tracks' => [[
    'id' => 'terminal', 'type' => 'glyph', 'anchor' => 'caster',
    'keyframes' => [['frame' => 0, 'duration' => 6, 'content' => '*']],
  ]], 'cues' => [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 4]],
    'effectTiming' => ['mode' => 'cue', 'cueId' => 'hit']];
});

afterEach(function () {
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->effectRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) {
    $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
  }
  rmdir($this->effectRoot);
});

it('preserves independent sequences under the same effect identity', function () {
  $data = ['presentations' => ['terminal' => $this->terminalSequence, 'graphical' => $this->imageSequence]];
  $terminal = $this->effects->compile('shared', $data, true, EffectPresentation::TERMINAL);
  $graphical = $this->effects->compile('shared', $data, true);
  expect($terminal->sourceId)->toBe($graphical->sourceId)->toBe('shared')
    ->and($terminal->fps)->toBe(10)->and($graphical->fps)->toBe(12)
    ->and($terminal->defaults['lengthFrames'])->toBe(6)->and($graphical->defaults['lengthFrames'])->toBe(2)
    ->and($terminal->defaults['restFrame'])->toBe(4)->and($graphical->defaults['restFrame'])->toBe(1)
    ->and($terminal->cueSchedule)->toBe($this->terminalSequence['cues'])
    ->and($terminal->defaults['effectTiming'])->toBe($this->terminalSequence['effectTiming'])
    ->and($graphical->cueSchedule)->toBeEmpty()
    ->and($terminal->playbackSegments[0]['drawCommands'][0]['payload']['anchor'])->toBe('caster')
    ->and($graphical->playbackSegments[1]['drawCommands'][0]['payload']['sourceFrame'])->toBe(1);
});

it('filters mixed tracks by capability while preserving shared text', function () {
  $tracks = [...$this->imageSequence['tracks'],
    ['id' => 'terminal', 'type' => 'glyph', 'presentation' => 'terminal', 'keyframes' => [['frame' => 0, 'content' => '/']]],
    ['id' => 'graphical', 'type' => 'glyph', 'presentation' => 'graphical', 'keyframes' => [['frame' => 0, 'content' => 'o']]],
    ['id' => 'shared', 'type' => 'text', 'keyframes' => [['frame' => 0, 'content' => 'Impact']]],
  ];
  foreach ([EffectPresentation::TERMINAL, EffectPresentation::GRAPHICAL] as $presentation) {
    $compiled = $this->effects->compile('mixed', [...$this->imageSequence, 'tracks' => $tracks], true, $presentation);
    $ids = array_column(array_merge(...array_column($compiled->playbackSegments, 'drawCommands')), 'trackId');
    expect($ids)->toContain('shared', $presentation->value)
      ->and($ids)->not->toContain($presentation === EffectPresentation::TERMINAL ? 'graphical' : 'terminal');
    expect(in_array('image', $ids, true))->toBe($presentation === EffectPresentation::GRAPHICAL);
  }
});

it('does not make missing graphical resources dependencies of the terminal cache', function () {
  $image = $this->imageSequence;
  $image['tracks'][0]['asset'] = 'missing.png';
  $data = ['presentations' => ['terminal' => $this->terminalSequence, 'graphical' => $image]];
  mkdir($this->effectRoot . '/Animations/shared', 0777, true);
  file_put_contents($this->effectRoot . '/Animations/shared/shared.timeline.php', '<?php return ' . var_export($data, true) . ';');
  $terminal = $this->effects->load('shared', true, EffectPresentation::TERMINAL);
  expect(fn() => $this->effects->load('shared', true))->toThrow(RuntimeException::class)
    ->and($this->effects->load('shared', true, EffectPresentation::TERMINAL))->toBe($terminal);
});

it('validates sequence rest coverage rather than requiring every later stroke to be present', function () {
  $first = $this->imageSequence['tracks'][0];
  $second = [...$first, 'id' => 'later', 'keyframes' => [['frame' => 3, 'sourceFrame' => 0]]];
  $data = [...$this->imageSequence, 'lengthFrames' => 4, 'tracks' => [$first, $second]];
  expect($this->effects->compile('strokes', $data)->playbackSegments)->toHaveCount(3);
  $data['restFrame'] = 2;
  expect(fn() => $this->effects->compile('strokes', $data))
    ->toThrow(InvalidArgumentException::class, 'no authored rest presentation');
});

it('shares track scope validation and round trips between summons and effects', function (mixed $scope) {
  $track = ['id' => 'track', 'type' => 'glyph', 'presentation' => $scope, 'keyframes' => [['frame' => 0, 'content' => '+']]];
  if (in_array($scope, ['all', 'terminal', 'graphical'], true)) {
    $roundTrip = SummonCutsceneTrack::fromArray($track)->toArray();
    expect((new EffectTimelineCompiler())->compileTracks([$roundTrip])[0]['presentation'])->toBe($scope);
  } else {
    expect(fn() => SummonCutsceneTrack::fromArray($track))->toThrow(InvalidArgumentException::class)
      ->and(fn() => (new EffectTimelineCompiler())->compileTracks([$track]))->toThrow(InvalidArgumentException::class);
  }
})->with(['shared' => 'all', 'terminal' => 'terminal', 'graphical' => 'graphical',
  'renderer name' => 'gpui', 'empty' => '', 'non-string' => 1]);

it('refuses mixed incomplete or nested sequence ownership', function (array $data) {
  expect(fn() => $this->effects->compile('bad', $data, true))->toThrow(InvalidArgumentException::class);
})->with([
  'missing counterpart' => [['presentations' => ['graphical' => []]]],
  'unknown counterpart' => [['presentations' => ['terminal' => [], 'graphical' => [], 'other' => []]]],
  'nested sequence' => [['presentations' => ['terminal' => ['presentations' => []], 'graphical' => []]]],
  'flat and variants' => [['fps' => 10, 'presentations' => ['terminal' => [], 'graphical' => []]]],
]);

it('refuses a timeline symlink escaping the asset root without evaluating it', function () {
  $outside = tempnam(sys_get_temp_dir(), 'ichiloto-effect-escape-');
  file_put_contents($outside, '<?php throw new LogicException("must not execute");');
  mkdir($this->effectRoot . '/Animations/escape', 0777, true);
  symlink($outside, $this->effectRoot . '/Animations/escape/escape.timeline.php');
  try {
    expect(fn() => $this->effects->load('escape', true))->toThrow(InvalidArgumentException::class, 'inside the asset root');
  } finally {
    unlink($outside);
  }
});

it('lists the timelines an asset root holds by identity, without reading them', function () {
  foreach (['wave', 'ember-burst', 'Bad Id', 'misnamed'] as $folder) {
    mkdir($this->effectRoot . "/Animations/{$folder}", 0777, true);
  }
  file_put_contents($this->effectRoot . '/Animations/wave/wave.timeline.php', '<?php throw new LogicException("must not execute");');
  file_put_contents($this->effectRoot . '/Animations/ember-burst/ember-burst.timeline.php', '<?php return [];');
  file_put_contents($this->effectRoot . '/Animations/Bad Id/Bad Id.timeline.php', '<?php return [];');
  file_put_contents($this->effectRoot . '/Animations/misnamed/other.timeline.php', '<?php return [];');
  $outside = tempnam(sys_get_temp_dir(), 'ichiloto-effect-escape-');
  mkdir($this->effectRoot . '/Animations/escape');
  symlink($outside, $this->effectRoot . '/Animations/escape/escape.timeline.php');

  try {
    expect($this->effects->findTimelineIds())->toBe(['ember-burst', 'wave'])
      ->and(new EffectTimelineLibrary($this->effectRoot . '/missing')->findTimelineIds())->toBe([]);
  } finally {
    unlink($outside);
  }
});
it('loads field glyphs text and sound cues independently of missing graphical art', function () {
  $terminal = ['fps' => 25, 'lengthFrames' => 18, 'tracks' => [
    ['id' => 'glyph', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'duration' => 3, 'content' => "* *\n\n + ", 'color' => 'cyan']]],
    ['id' => 'label', 'type' => 'text', 'anchor' => 'screen', 'keyframes' => [['frame' => 3, 'content' => 'Aura']]],
  ], 'cues' => [['id' => 'sound', 'frame' => 3, 'type' => 'playSound', 'payload' => ['sound' => 'aura']]]];
  $image = $this->imageSequence;
  $image['tracks'][0]['asset'] = 'missing.png';
  $data = ['presentations' => ['terminal' => $terminal, 'graphical' => $image]];
  $compiled = $this->effects->compile('field', $data, presentation: EffectPresentation::TERMINAL);
  expect($compiled->fps)->toBe(25)->and($compiled->defaults['lengthFrames'])->toBe(18)
    ->and(array_column($compiled->playbackSegments, 'layer'))->toBe(['glyph', 'text'])
    ->and($compiled->playbackSegments[0]['drawCommands'][0]['content'])->toBe("* *\n\n + ")
    ->and($compiled->cueSchedule)->toBe($terminal['cues']);
  expect(fn() => $this->effects->compile('field', $data))->toThrow(RuntimeException::class);
});

it('refuses unsupported or gameplay-owning field cues rather than silently dropping them', function (string $type) {
  $data = ['fps' => 5, 'lengthFrames' => 1, 'tracks' => [[
    'id' => 'glyph', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'content' => '*']],
  ]], 'cues' => [['id' => 'unsupported', 'frame' => 0, 'type' => $type]]];
  expect(fn() => $this->effects->compile('field', $data))
    ->toThrow(InvalidArgumentException::class, 'field presentation cue');
})->with(['impact' => 'applyEffect', 'message' => 'showMessage', 'flash' => 'flash', 'shake' => 'shake']);
