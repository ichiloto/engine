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

it('preserves explicit image fitting without adding a new default to existing sources', function (bool $battle) {
  $legacy = $this->effects->compile('legacy-fit', $this->imageSequence, $battle);
  expect($legacy->playbackSegments[0]['drawCommands'][0]['payload'])->not->toHaveKey('fit');
  foreach (['contain', 'stretch'] as $fit) {
    $source = $this->imageSequence;
    $source['tracks'][0]['fit'] = $fit;
    $compiled = $this->effects->compile('explicit-fit', $source, $battle);
    expect($compiled->playbackSegments[0]['drawCommands'][0]['payload']['fit'])->toBe($fit);
  }
})->with([false, true]);

it('refuses invalid image fitting at the shared authored boundary', function (mixed $fit) {
  $this->imageSequence['tracks'][0]['fit'] = $fit;
  foreach ([false, true] as $battle) {
    expect(fn() => $this->effects->compile('bad-fit', $this->imageSequence, $battle))
      ->toThrow(InvalidArgumentException::class, 'Image fit');
  }
})->with(['unknown' => 'cover', 'null' => null, 'numeric' => 1, 'descriptor' => [[]]]);

it('keeps battle phase cadence independently authored per presentation', function () {
  $terminal = [...$this->terminalSequence, 'cadence' => 'battle_phase'];
  unset($terminal['fps']);
  $data = ['presentations' => ['terminal' => $terminal, 'graphical' => $this->imageSequence]];
  $compiled = $this->effects->compile('phase-cadence', $data, true, EffectPresentation::TERMINAL);
  expect($compiled->defaults['cadence'])->toBe('battle_phase')
    ->and($this->effects->compile('phase-cadence', $data, true)->cadence->value)->toBe('fixed');
  expect(fn() => $this->effects->compile('field-cadence', [...$this->imageSequence, 'cadence' => 'battle_phase']))
    ->toThrow(InvalidArgumentException::class);
  expect(fn() => $this->effects->compile('loop-cadence', [...$terminal, 'playback' => 'loop'], true))
    ->toThrow(InvalidArgumentException::class);
});

it('refuses two authored timing authorities for battle phase cadence', function () {
  expect(fn() => $this->effects->compile('two-clocks', [...$this->terminalSequence, 'cadence' => 'battle_phase'], true))
    ->toThrow(InvalidArgumentException::class);
});

it('rejects invalid cadence values at the authored boundary', function (mixed $cadence) {
  expect(fn() => $this->effects->compile('invalid-cadence', [...$this->imageSequence, 'cadence' => $cadence], true))
    ->toThrow(InvalidArgumentException::class);
})->with(['unknown' => 'paced', 'empty' => '', 'null' => null, 'number' => 1, 'descriptor' => [[]]]);

it('compiles battle direction and independent image flips through the shared timeline', function () {
  $this->imageSequence['tracks'][0]['facing'] = 'west';
  $this->imageSequence['tracks'][0]['anchor'] = 'target';
  $this->imageSequence['tracks'][0]['keyframes'][1]['flipY'] = true;
  $image = $this->effects->compile('direction', $this->imageSequence, true);
  expect($image->playbackSegments[1]['drawCommands'][0]['payload'])
    ->toMatchArray(['facing' => 'west', 'flipY' => true, 'sourceFrame' => 1]);
  $this->terminalSequence['tracks'][0]['facing'] = 'east';
  $glyph = $this->effects->compile('direction', $this->terminalSequence, true, EffectPresentation::TERMINAL);
  expect($glyph->playbackSegments[0]['drawCommands'][0]['payload']['facing'])->toBe('east');
  expect(fn() => $this->effects->compile('field-direction', $this->imageSequence))
    ->toThrow(InvalidArgumentException::class, 'facing');
});

it('shares normalized image pivots while keeping battler attachments out of field geometry', function () {
  expect($this->effects->compile('default-pivot', $this->imageSequence)->playbackSegments[0]['drawCommands'][0]['payload'])
    ->not->toHaveKey('pivot');
  $this->imageSequence['tracks'][0]['pivot'] = ['x' => .2, 'y' => .75];
  $field = $this->effects->compile('field-pivot', $this->imageSequence);
  expect($field->playbackSegments[1]['drawCommands'][0]['payload']['pivot'])->toBe(['x' => .2, 'y' => .75]);
  $this->imageSequence['tracks'][0]['attachment'] = 'ground';
  $battle = $this->effects->compile('ground', $this->imageSequence, true);
  expect($battle->playbackSegments[1]['drawCommands'][0]['payload'])
    ->toMatchArray(['pivot' => ['x' => .2, 'y' => .75], 'attachment' => 'ground']);
  expect(fn() => $this->effects->compile('field-attachment', $this->imageSequence))
    ->toThrow(InvalidArgumentException::class);
});

it('refuses malformed or nonfinite normalized image pivots', function (mixed $pivot) {
  $this->imageSequence['tracks'][0]['pivot'] = $pivot;
  foreach ([false, true] as $battle) {
    expect(fn() => $this->effects->compile('bad-pivot', $this->imageSequence, $battle))
      ->toThrow(InvalidArgumentException::class);
  }
})->with(['null' => null, 'missing axis' => [['x' => .5]], 'extra key' => [['x' => .5, 'y' => .75, 'z' => 0]],
  'text' => [['x' => '0.5', 'y' => 1]], 'negative' => [['x' => -.1, 'y' => .5]],
  'outside' => [['x' => .5, 'y' => 1.1]], 'infinite' => [['x' => .5, 'y' => INF]],
  'nan' => [['x' => NAN, 'y' => .5]]]);

it('accepts image pivot boundaries without admitting battle placement or image flips on the field', function () {
  foreach ([['x' => 0, 'y' => 1], ['x' => 1, 'y' => 0]] as $pivot) {
    $source = $this->imageSequence;
    $source['tracks'][0]['pivot'] = $pivot;
    expect($this->effects->compile('boundary-pivot', $source)->playbackSegments[0]['drawCommands'][0]['payload']['pivot'])
      ->toBe($pivot);
    foreach (['anchor' => 'target', 'attachment' => 'ground', 'facing' => 'west'] as $key => $value) {
      $bad = $source;
      $bad['tracks'][0][$key] = $value;
      expect(fn() => $this->effects->compile('battle-only', $bad))->toThrow(InvalidArgumentException::class);
    }
    $source['tracks'][0]['keyframes'][0]['flipX'] = true;
    expect(fn() => $this->effects->compile('battle-flip', $source))->toThrow(InvalidArgumentException::class);
  }
});

it('refuses invalid or screen-relative battler attachments', function () {
  foreach (['feet', 'unknown', '', null, 1] as $attachment) {
    $bad = $this->imageSequence;
    $bad['tracks'][0]['attachment'] = $attachment;
    expect(fn() => $this->effects->compile('bad-attachment', $bad, true))->toThrow(InvalidArgumentException::class);
  }
  $this->imageSequence['tracks'][0]['attachment'] = 'ground';
  $this->imageSequence['tracks'][0]['anchor'] = 'screen';
  expect(fn() => $this->effects->compile('screen-attachment', $this->imageSequence, true))
    ->toThrow(InvalidArgumentException::class);
});

it('rejects invalid or screen-relative direction and nonboolean image flips', function () {
  foreach (['north', '', null, 1] as $facing) {
    $bad = $this->imageSequence;
    $bad['tracks'][0]['facing'] = $facing;
    expect(fn() => $this->effects->compile('bad-direction', $bad, true))->toThrow(InvalidArgumentException::class);
  }
  $bad = $this->imageSequence;
  $bad['tracks'][0]['facing'] = 'west';
  $bad['tracks'][0]['anchor'] = 'screen';
  expect(fn() => $this->effects->compile('bad-direction', $bad, true))->toThrow(InvalidArgumentException::class);
  foreach (['true', 1, null] as $flip) {
    $bad = $this->imageSequence;
    $bad['tracks'][0]['keyframes'][0]['flipX'] = $flip;
    expect(fn() => $this->effects->compile('bad-flip', $bad, true))->toThrow(InvalidArgumentException::class);
  }
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
