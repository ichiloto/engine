<?php

use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Cutscenes\Summons\SummonCue;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneCompiler;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneTrack;
use Ichiloto\Engine\Cutscenes\Summons\SummonEffectTiming;
use Ichiloto\Engine\Cutscenes\Summons\SummonPlaybackConfig;
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
