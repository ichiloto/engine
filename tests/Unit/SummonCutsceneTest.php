<?php

use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Cutscenes\Summons\SummonCue;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneCompiler;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneTrack;
use Ichiloto\Engine\Cutscenes\Summons\SummonEffectTiming;
use Ichiloto\Engine\Cutscenes\Summons\SummonEffectTrigger;
use Ichiloto\Engine\Cutscenes\Summons\SummonPlaybackConfig;
use Ichiloto\Engine\Cutscenes\Summons\SummonPlaybackSession;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutscenePlayer;
use Ichiloto\Engine\Cutscenes\Summons\SummonTargetPresentation;
use Ichiloto\Engine\Cutscenes\Summons\SummonTransitionDefinition;

it('hydrates summon cutscene definitions from source arrays', function () {
  $definition = SummonCutsceneDefinition::fromArrays(
    [
      'id' => 'ifrit',
      'name' => 'Ifrit',
      'playback' => ['defaultSpeed' => 1.25],
      'transitionIn' => ['type' => 'fadeToBlack', 'durationMs' => 650],
      'transitionOut' => ['type' => 'fadeFromBlack', 'durationMs' => 500],
      'effectTiming' => ['mode' => 'cue', 'cueId' => 'apply_ifrit_damage'],
      'targetPresentation' => ['mode' => 'full_screen', 'showCasterNameBanner' => true],
      'authoring' => ['compileVersion' => 1],
    ],
    [
      'formatVersion' => 1,
      'fps' => 24,
      'lengthFrames' => 360,
      'tracks' => [
        [
          'type' => 'glyph',
          'id' => 'flame_arc',
          'keyframes' => [
            [
              'frame' => 96,
              'duration' => 18,
              'position' => ['x' => 22, 'y' => 9],
              'assetId' => 'flame_arc_large',
              'color' => 'red',
            ],
          ],
        ],
      ],
      'cues' => [
        ['id' => 'apply_ifrit_damage', 'frame' => 214, 'type' => 'applyEffect'],
      ],
    ],
  );

  expect($definition->id)->toBe('ifrit')
    ->and($definition->playback)->toBeInstanceOf(SummonPlaybackConfig::class)
    ->and($definition->playback->defaultSpeed)->toBe(1.25)
    ->and($definition->transitionIn)->toBeInstanceOf(SummonTransitionDefinition::class)
    ->and($definition->transitionIn->durationMs)->toBe(650)
    ->and($definition->effectTiming)->toBeInstanceOf(SummonEffectTiming::class)
    ->and($definition->effectTiming->cueId)->toBe('apply_ifrit_damage')
    ->and($definition->targetPresentation)->toBeInstanceOf(SummonTargetPresentation::class)
    ->and($definition->getTracks())->toHaveCount(1)
    ->and($definition->getTracks()[0])->toBeInstanceOf(SummonCutsceneTrack::class)
    ->and($definition->getCues())->toHaveCount(1)
    ->and($definition->getCueById('apply_ifrit_damage'))->toBeInstanceOf(SummonCue::class);
});

it('compiles summon cutscenes into runtime playback segments', function () {
  $definition = SummonCutsceneDefinition::fromArrays(
    [
      'id' => 'ifrit',
      'name' => 'Ifrit',
      'playback' => ['defaultSpeed' => 1.0],
      'transitionIn' => ['type' => 'fadeToBlack', 'durationMs' => 650],
      'transitionOut' => ['type' => 'fadeFromBlack', 'durationMs' => 500],
      'effectTiming' => ['mode' => 'cue', 'cueId' => 'apply_ifrit_damage'],
    ],
    [
      'fps' => 24,
      'lengthFrames' => 360,
      'tracks' => [
        [
          'type' => 'text',
          'id' => 'title_1',
          'keyframes' => [
            ['frame' => 18, 'duration' => 42, 'content' => 'IFRIT', 'position' => [34, 4], 'zIndex' => 2],
          ],
        ],
        [
          'type' => 'glyph',
          'id' => 'flame_arc_1',
          'keyframes' => [
            ['frame' => 96, 'duration' => 18, 'assetId' => 'flame_arc_large', 'position' => [22, 9], 'color' => 'red', 'zIndex' => 1],
          ],
        ],
      ],
      'cues' => [
        ['id' => 'apply_ifrit_damage', 'frame' => 214, 'type' => 'applyEffect'],
        ['id' => 'restore_battlefield', 'frame' => 330, 'type' => 'restoreBattlefield'],
      ],
    ],
  );

  $compiled = (new SummonCutsceneCompiler())->compile($definition);

  expect($compiled)->toBeInstanceOf(SummonCompiledCutscene::class)
    ->and($compiled->sourceId)->toBe('ifrit')
    ->and($compiled->fps)->toBe(24)
    ->and($compiled->playbackSegments)->toHaveCount(2)
    ->and($compiled->playbackSegments[0]['startFrame'])->toBe(18)
    ->and($compiled->cueSchedule)->toHaveCount(2)
    ->and($compiled->cueSchedule[0]['id'])->toBe('apply_ifrit_damage');
});

it('loads folder-based summon assets and compiles them when needed', function () {
  $root = sys_get_temp_dir() . '/ichiloto-summon-library-' . uniqid();
  $directory = $root . '/ifrit';
  mkdir($directory, 0777, true);

  file_put_contents($directory . '/ifrit.data.php', <<<'PHP'
<?php

return [
  'id' => 'ifrit',
  'name' => 'Ifrit',
  'playback' => ['defaultSpeed' => 1.0],
  'transitionIn' => ['type' => 'fadeToBlack', 'durationMs' => 650],
  'transitionOut' => ['type' => 'fadeFromBlack', 'durationMs' => 500],
  'effectTiming' => ['mode' => 'cue', 'cueId' => 'apply_ifrit_damage'],
];
PHP);

  file_put_contents($directory . '/ifrit.timeline.php', <<<'PHP'
<?php

return [
  'fps' => 24,
  'lengthFrames' => 360,
  'tracks' => [
    [
      'type' => 'glyph',
      'id' => 'flame_arc_1',
      'keyframes' => [
        ['frame' => 96, 'duration' => 18, 'assetId' => 'flame_arc_large', 'position' => [22, 9], 'color' => 'red'],
      ],
    ],
  ],
  'cues' => [
    ['id' => 'apply_ifrit_damage', 'frame' => 214, 'type' => 'applyEffect'],
  ],
];
PHP);

  $library = new SummonCutsceneLibrary($root);
  $definition = $library->findById('ifrit');
  $compiled = $library->loadCompiledOrCompile('ifrit');

  expect($definition)->toBeInstanceOf(SummonCutsceneDefinition::class)
    ->and($compiled)->toBeInstanceOf(SummonCompiledCutscene::class)
    ->and($compiled->sourceId)->toBe('ifrit');

  unlink($directory . '/ifrit.data.php');
  unlink($directory . '/ifrit.timeline.php');
  rmdir($directory);
  rmdir($root);
});

it('round-trips codex fields through the definition', function () {
  $definition = SummonCutsceneDefinition::fromArrays(
    [
      'id' => 'codex-summon',
      'name' => 'Codex Summon',
      'lore' => 'An ancient spirit.',
      'element' => 'Fire',
      'strengths' => ['Ice', ''],
      'weaknesses' => ['Water'],
      'attributes' => ['Power' => 'A'],
    ],
    ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []],
  );

  expect($definition->lore)->toBe('An ancient spirit.')
    ->and($definition->element)->toBe('Fire')
    ->and($definition->strengths)->toBe(['Ice'])
    ->and($definition->weaknesses)->toBe(['Water'])
    ->and($definition->attributes)->toBe(['Power' => 'A']);

  $data = $definition->toDataArray();
  expect($data['lore'])->toBe('An ancient spirit.')
    ->and($data['element'])->toBe('Fire')
    ->and($data['strengths'])->toBe(['Ice'])
    ->and($data['attributes'])->toBe(['Power' => 'A']);
});

it('keeps compiled summon definitions stable for one battle and reloads in the next', function () {
  $root = sys_get_temp_dir() . '/ichiloto-summon-cache-' . uniqid();
  $directory = $root . '/first';
  mkdir($directory, 0777, true);
  $data = $directory . '/first.data.php';
  $timeline = $directory . '/first.timeline.php';
  try {
    file_put_contents($data, "<?php return ['id' => 'first', 'name' => 'Before', 'linkedActionId' => 'Call', 'effectTiming' => ['mode' => 'end']];");
    file_put_contents($timeline, "<?php return ['fps' => 12, 'lengthFrames' => 2, 'tracks' => [], 'cues' => []];");
    $battle = new SummonCutsceneLibrary($root, cacheForBattle: true);
    $first = $battle->loadCompiledOrCompileByLinkedActionId('Call');
    file_put_contents($data, "<?php return ['id' => 'first', 'name' => 'After', 'linkedActionId' => 'Call', 'effectTiming' => ['mode' => 'end']];");
    expect($battle->loadCompiledOrCompileByLinkedActionId('Call'))->toBe($first)
      ->and($battle->findById('first')?->name)->toBe('Before')
      ->and((new SummonCutsceneLibrary($root, cacheForBattle: true))->findById('first')?->name)->toBe('After');
  } finally {
    unlink($data); unlink($timeline); rmdir($directory); rmdir($root);
  }
});

it('keeps valid summons available when a neighbouring optional definition is malformed', function () {
  $root = sys_get_temp_dir() . '/ichiloto-summon-invalid-' . uniqid();
  $valid = $root . '/valid';
  $invalid = $root . '/invalid';
  mkdir($valid, 0777, true);
  mkdir($invalid, 0777, true);
  try {
    file_put_contents($valid . '/valid.data.php', "<?php return ['id' => 'valid', 'name' => 'Valid', 'linkedActionId' => 'Call', 'effectTiming' => ['mode' => 'end']];");
    file_put_contents($valid . '/valid.timeline.php', "<?php return ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []];");
    file_put_contents($invalid . '/invalid.data.php', "<?php return 'bad';");
    file_put_contents($invalid . '/invalid.timeline.php', "<?php return [];");
    $library = new SummonCutsceneLibrary($root, cacheForBattle: true);
    expect(array_map(static fn($definition): string => $definition->id, $library->load()))->toBe(['valid'])
      ->and($library->loadCompiledOrCompileByLinkedActionId('Call'))->toBeInstanceOf(SummonCompiledCutscene::class);
  } finally {
    foreach ([$valid . '/valid.data.php', $valid . '/valid.timeline.php', $invalid . '/invalid.data.php', $invalid . '/invalid.timeline.php'] as $path) {
      unlink($path);
    }
    rmdir($valid); rmdir($invalid); rmdir($root);
  }
});

it('keeps an omitted wielder policy omitted through source round-trip', function () {
  $definition = SummonCutsceneDefinition::fromArrays(
    ['id' => 'open-summon', 'name' => 'Open Summon'],
    ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []],
  );
  $serialized = $definition->toDataArray();
  $restored = SummonCutsceneDefinition::fromArrays(
    $serialized,
    $definition->toTimelineArray(),
  );

  expect($serialized)->not->toHaveKey('wielders')
    ->and($restored->wielders)->toBeNull()
    ->and($restored->toDataArray())->not->toHaveKey('wielders');
});

function makePlaybackCutscene(bool $loop = false): SummonCompiledCutscene
{
  return new SummonCompiledCutscene(
    sourceId: 'playback-fixture',
    sourceHash: 'fixture-hash',
    fps: 10,
    playbackSegments: [
      ['id' => 'opening', 'startFrame' => 0, 'endFrame' => 2],
      ['id' => 'finish', 'startFrame' => 3, 'endFrame' => 4],
    ],
    cueSchedule: [
      ['id' => 'opening', 'frame' => 0, 'type' => 'sound'],
      ['id' => 'first', 'frame' => 1, 'type' => 'sound'],
      ['id' => 'second', 'frame' => 2, 'type' => 'applyEffect'],
      ['id' => 'finish', 'frame' => 4, 'type' => 'restoreBattlefield'],
    ],
    defaults: [
      'lengthFrames' => 5,
      'playback' => ['defaultSpeed' => 1.0, 'loop' => $loop],
    ],
  );
}

it('advances summon playback by elapsed time without dropping crossed cues', function () {
  $session = new SummonPlaybackSession(makePlaybackCutscene());

  expect($session->totalFrames)->toBe(5)
    ->and($session->fps)->toBe(10)
    ->and($session->effectiveSpeed)->toBe(1.0)
    ->and($session->secondsPerFrame)->toBe(0.1)
    ->and($session->currentFrame)->toBe(0)
    ->and(array_column($session->activeSegments(), 'id'))->toBe(['opening']);

  $partial = $session->update(0.05);
  $first = $session->update(0.05);
  $sameFrame = $session->update(0.05);
  $large = $session->update(0.25);

  expect($partial->crossedFrames)->toBe([])
    ->and(array_column($partial->crossedCues, 'id'))->toBe(['opening'])
    ->and($first->crossedFrames)->toBe([1])
    ->and(array_column($first->crossedCues, 'id'))->toBe(['first'])
    ->and($sameFrame->crossedCues)->toBe([])
    ->and($large->crossedFrames)->toBe([2, 3, 4])
    ->and(array_column($large->crossedCues, 'id'))->toBe(['second', 'finish'])
    ->and($session->currentFrame)->toBe(4)
    ->and(array_column($session->activeSegments(), 'id'))->toBe(['finish']);

  $completed = $session->update(0.1);
  expect($completed->crossedFrames)->toBe([])
    ->and($session->isCompleted)->toBeTrue();
});

it('keeps inspection, seeking, pausing, stepping, looping and restart deterministic', function () {
  $session = new SummonPlaybackSession(makePlaybackCutscene(loop: true));

  $session->seek(99);
  expect($session->currentFrame)->toBe(4)
    ->and(array_column($session->cuesAt(), 'id'))->toBe(['finish'])
    ->and($session->traversal)->toBe(0);

  $session->pause();
  expect($session->update(1.0)->crossedFrames)->toBe([])
    ->and($session->currentFrame)->toBe(4);

  $session->resume();
  $looped = $session->update(0.1);
  expect($looped->crossedFrames)->toBe([0])
    ->and(array_column($looped->crossedCues, 'id'))->toBe(['opening'])
    ->and($session->traversal)->toBe(1)
    ->and($session->isCompleted)->toBeFalse();

  $session->stepForward();
  $session->stepForward();
  $session->stepBackward();
  expect($session->currentFrame)->toBe(1)
    ->and($session->traversal)->toBe(1);

  $session->restart();
  expect($session->currentFrame)->toBe(0)
    ->and($session->traversal)->toBe(2)
    ->and($session->isPaused)->toBeFalse()
    ->and($session->isCompleted)->toBeFalse();

  $restarted = $session->update(0.01);
  expect($restarted->crossedFrames)->toBe([])
    ->and(array_column($restarted->crossedCues, 'id'))->toBe(['opening'])
    ->and($session->update(0.01)->crossedCues)->toBe([]);
});

it('keeps the blocking summon player source-compatible through the shared session', function () {
  $cutscene = new SummonCompiledCutscene(
    sourceId: 'blocking-fixture',
    sourceHash: 'fixture-hash',
    fps: 1000,
    playbackSegments: [['id' => 'visible', 'startFrame' => 0, 'endFrame' => 2]],
    cueSchedule: [
      ['id' => 'opening', 'frame' => 0, 'type' => 'sound'],
      ['id' => 'effect', 'frame' => 2, 'type' => 'applyEffect'],
    ],
    defaults: ['lengthFrames' => 3, 'playback' => ['defaultSpeed' => 1.0]],
  );
  $rendered = [];
  $cues = [];

  (new SummonCutscenePlayer())->play(
    $cutscene,
    function (int $frame, array $segments) use (&$rendered): void {
      $rendered[] = [$frame, array_column($segments, 'id')];
    },
    function (array $cue, int $frame) use (&$cues): void {
      $cues[] = [$cue['id'], $frame];
    },
  );

  expect($rendered)->toBe([
    [0, ['visible']],
    [1, ['visible']],
    [2, ['visible']],
  ])->and($cues)->toBe([
    ['opening', 0],
    ['effect', 2],
  ]);
});

it('fires each summon cue once and reduced motion retains ordered cues with only the final frame', function () {
  $cutscene = makePlaybackCutscene();
  $normal = $reduced = [];
  $normalFrames = $reducedFrames = [];
  (new SummonCutscenePlayer())->play($cutscene,
    function (int $frame) use (&$normalFrames): void { $normalFrames[] = $frame; },
    function (array $cue) use (&$normal): void { $normal[] = $cue['id']; });
  (new SummonCutscenePlayer())->play($cutscene,
    function (int $frame) use (&$reducedFrames): void { $reducedFrames[] = $frame; },
    function (array $cue) use (&$reduced): void { $reduced[] = $cue['id']; },
    reducedMotion: true);
  expect($normalFrames)->toBe([0, 1, 2, 3, 4])
    ->and($reducedFrames)->toBe([4])
    ->and($normal)->toBe(['opening', 'first', 'second', 'finish'])
    ->and($reduced)->toBe($normal);
});

it('continues the summon cue timeline after its renderer fails before impact', function () {
  $cutscene = makePlaybackCutscene();
  $events = [];
  $frameAtEffect = null;
  $trigger = new SummonEffectTrigger(['mode' => 'cue', 'cueId' => 'second'],
    function () use (&$frameAtEffect, &$events): void { $frameAtEffect = end($events); });
  expect(function () use ($cutscene, $trigger, &$events): void { (new SummonCutscenePlayer())->play($cutscene,
    static function (): void { throw new RuntimeException('display offline'); },
    function (array $cue, int $frame) use ($trigger, &$events): void {
      $events[] = $frame;
      $trigger->onCue($cue);
    }); })
    ->toThrow(RuntimeException::class, 'display offline');
  $trigger->finish();
  expect($events)->toBe([0, 1, 2, 4])->and($frameAtEffect)->toBe(2);
});

it('applies summon combat exactly once at end cue or explicit frame and after presentation failure', function () {
  foreach ([
    [['mode' => 'end'], null, null],
    [['mode' => 'cue', 'cueId' => 'impact'], 'impact', null],
    [['mode' => 'explicit_frame', 'frame' => 2], null, 2],
    [['mode' => 'frame', 'frame' => 2], null, 2],
  ] as [$timing, $cueId, $frame]) {
    $count = 0;
    $trigger = new SummonEffectTrigger($timing, function () use (&$count): void { $count++; });
    $trigger->onFrame(0);
    $trigger->onCue(['id' => 'other']);
    expect($count)->toBe(0);
    if ($cueId !== null) { $trigger->onCue(['id' => $cueId]); }
    if ($frame !== null) { $trigger->onFrame($frame); }
    expect($count)->toBe($cueId !== null || $frame !== null ? 1 : 0);
    $trigger->finish(); $trigger->finish();
    expect($count)->toBe(1);
  }
  $count = 0;
  $trigger = new SummonEffectTrigger(['mode' => 'cue', 'cueId' => 'never-reached'],
    function () use (&$count): void { $count++; });
  try { throw new RuntimeException('presentation failed'); }
  catch (RuntimeException) { /* Runtime reports the presentation failure after the effect fallback. */ }
  finally { $trigger->finish(); }
  expect($count)->toBe(1);
});
