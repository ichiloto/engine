<?php

use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleEffects;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStagePresentation;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneCompiler;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->root = createTestDirectory('summon-cover-ownership-');
  writeTestPng($this->root . '/sheet.png', 16, 8);
  $this->data = SummonCutsceneDefinition::fromArrays([
    'id' => 'synthetic-cover', 'name' => 'Synthetic Cover', 'linkedActionId' => 'synthetic-action',
    'transitionIn' => ['type' => 'fadeToBlack', 'durationMs' => 500, 'color' => '#334455',
      'easing' => 'smoothstep', 'maskAssetId' => 'replaceable-mask'],
    'transitionOut' => ['type' => 'fadeFromBlack', 'durationMs' => 250, 'color' => '#556677'],
    'effectTiming' => ['mode' => 'cue', 'cueId' => 'contact'],
  ], [])->toDataArray();
  $this->terminal = ['fps' => 10, 'lengthFrames' => 6, 'restFrame' => 4,
    'tracks' => [['id' => 'text', 'type' => 'glyph', 'presentation' => 'terminal',
      'keyframes' => [['frame' => 0, 'duration' => 6, 'content' => '*', 'position' => [1, 1]]]]],
    'cues' => [['id' => 'contact', 'type' => 'applyEffect', 'frame' => 3]]];
  $this->graphical = ['fps' => 20, 'lengthFrames' => 8, 'restFrame' => 3,
    'stage' => ['canvas' => ['width' => 320, 'height' => 180], 'startFrame' => 1, 'restoreFrame' => 6,
      'camera' => [['id' => 'wide', 'frame' => 0, 'focus' => ['x' => 160, 'y' => 90], 'zoom' => 1]],
      'covers' => [
        ['id' => 'clear', 'frame' => 0, 'color' => 'black', 'opacity' => 0],
        ['id' => 'entry', 'frame' => 1, 'color' => 'black', 'opacity' => 1],
        ['id' => 'restore', 'frame' => 6, 'color' => 'white', 'opacity' => 1],
        ['id' => 'exit', 'frame' => 7, 'color' => 'white', 'opacity' => 0],
      ]],
    'tracks' => [['id' => 'body', 'type' => 'image', 'presentation' => 'graphical',
      'asset' => 'sheet.png', 'sheet' => ['columns' => 2, 'rows' => 1], 'anchor' => 'stage',
      'placement' => ['position' => ['x' => 160, 'y' => 160], 'size' => ['width' => 80, 'height' => 80]],
      'pivot' => ['x' => .5, 'y' => 1], 'keyframes' => [['frame' => 1, 'duration' => 5, 'sourceFrame' => 1]]]],
    'cues' => [['id' => 'contact', 'type' => 'applyEffect', 'frame' => 4],
      ['id' => 'restore', 'type' => 'restoreBattlefield', 'frame' => 6]]];
  $this->paired = ['formatVersion' => 1,
    'presentations' => ['terminal' => $this->terminal, 'graphical' => $this->graphical],
    'editor' => ['notes' => 'Synthetic replaceable art', 'rows' => ['body', 'text']]];
  $this->compiler = new SummonCutsceneCompiler(assetRoot: $this->root);
  $this->timings = new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1);
});

it('compiles selected stage covers without rewriting shared authored transitions or independent terminal timing', function () {
  $definition = SummonCutsceneDefinition::fromArrays($this->data, $this->paired);
  $graphic = $this->compiler->compile($definition);
  $terminal = $this->compiler->compile($definition, EffectPresentation::TERMINAL);
  expect($graphic->transitionCache)->toBe([
    'in' => [...$this->data['transitionIn'], 'durationMs' => 0],
    'out' => [...$this->data['transitionOut'], 'durationMs' => 0],
  ])->and($terminal->transitionCache)->toBe([
    'in' => $this->data['transitionIn'], 'out' => $this->data['transitionOut'],
  ])->and($definition->toDataArray())->toBe($this->data)
    ->and($definition->toTimelineArray())->toBe($this->paired)
    ->and($graphic->sourceHash)->toBe($terminal->sourceHash)
    ->and($graphic->sourceId)->toBe($terminal->sourceId)
    ->and($graphic->fps)->toBe(20)->and($terminal->fps)->toBe(10)
    ->and($graphic->defaults['lengthFrames'])->toBe(8)->and($terminal->defaults['lengthFrames'])->toBe(6)
    ->and($graphic->cueSchedule[0]['frame'])->toBe(4)->and($terminal->cueSchedule[0]['frame'])->toBe(3)
    ->and($graphic->defaults['effectTiming'])->toBe($terminal->defaults['effectTiming'])
    ->and($graphic->defaults['playback'])->toBe($terminal->defaults['playback']);
  $restored = SummonCutsceneDefinition::fromArrays($definition->toDataArray(), $definition->toTimelineArray());
  expect($restored->toSourceArray())->toBe(['data' => $this->data, 'timeline' => $this->paired])
    ->and($this->compiler->compile($restored)->toArray())->toBe($graphic->toArray());
  $graphicPlan = new BattleCommandTimeline($this->timings, target: $graphic, terminalTarget: $terminal);
  $terminalPlan = new BattleCommandTimeline($this->timings, target: $terminal);
  expect($graphicPlan->phases)->not->toHaveKeys(['summon-in', 'summon-title', 'summon-out'])
    ->and($terminalPlan->phases['summon-in']['length'])->toBe(60)
    ->and($terminalPlan->phases['summon-out']['length'])->toBe(30)
    ->and($terminalPlan->phases['target']['length'])->toBe(72)
    ->and($graphicPlan->getCommandFrameForAuthoredFrame('target', 4) - $graphicPlan->phases['target']['start'])->toBe(24)
    ->and($terminalPlan->getCommandFrameForAuthoredFrame('target', 3) - $terminalPlan->phases['target']['start'])->toBe(36);
});

it('keeps flat legacy compatibility and derives cover ownership only from the selected validated stage',
  function (bool $staged, EffectPresentation $presentation) {
    $source = $staged ? $this->graphical : $this->terminal;
    if ($staged) { $source['tracks'][] = $this->terminal['tracks'][0]; }
    $definition = SummonCutsceneDefinition::fromArrays($this->data, $source);
    $before = $definition->toSourceArray();
    $compiled = $this->compiler->compile($definition, $presentation);
    $ownsCovers = $staged && $presentation === EffectPresentation::GRAPHICAL;
    expect(isset($compiled->defaults['stage']))->toBe($ownsCovers)
      ->and($compiled->transitionCache['in']['durationMs'])->toBe($ownsCovers ? 0 : 500)
      ->and($compiled->transitionCache['out']['durationMs'])->toBe($ownsCovers ? 0 : 250)
      ->and($definition->toSourceArray())->toBe($before)
      ->and(SummonCutsceneDefinition::fromArrays($before['data'], $before['timeline'])->toSourceArray())->toBe($before);
    $plan = new BattleCommandTimeline($this->timings, target: SummonCompiledCutscene::fromArray($compiled->toArray()));
    expect(array_key_exists('summon-in', $plan->phases))->toBe(!$ownsCovers)
      ->and(array_key_exists('summon-out', $plan->phases))->toBe(!$ownsCovers);
  })->with([false, true])->with(EffectPresentation::cases());

it('never adds legacy covers to staged normal or reduced-motion frames and traverses the same outcome once',
  function (bool $reduced) {
    $compiled = $this->compiler->compile(SummonCutsceneDefinition::fromArrays($this->data, $this->paired));
    $plan = new BattleCommandTimeline($this->timings, target: $compiled);
    $actor = new Character('Synthetic Actor', 1, new Stats());
    $hits = 0;
    $cues = [];
    $playback = new BattleCommandPlayback($plan, $actor, [$actor], BattlePoseRole::SUMMON,
      static function () use (&$hits): void { $hits++; },
      static function (array $cue) use (&$cues): void { $cues[] = $cue; });
    $playback->begin();
    $previous = 0;
    foreach (range(0, 7) as $authored) {
      $tick = $plan->getCommandFrameForAuthoredFrame('target', $authored);
      $playback->update(($tick - $previous) / BattleCommandTimeline::FPS);
      $previous = $tick;
      $frame = $plan->getCinematicStageFrame($playback->session->currentFrame, $reduced);
      $canvas = CinematicStagePresentation::compose($frame, $compiled->playbackSegments, $this->root, 640, 360);
      $covers = array_values(array_filter($canvas->composites, static fn($item): bool => $item->id === 'cinematic-stage-cover'));
      expect($covers)->toHaveCount($reduced || $authored === 0 || $authored === 7 ? 0 : 1)
        ->and($frame->active)->toBe($authored >= 1 && $authored < 6)
        ->and($hits)->toBe($authored < 4 ? 0 : 1);
      $legacy = GraphicalBattleEffects::compose($playback,
        new BattleCanvasLayout(640, 360, uiCellWidth: 2, uiCellHeight: 4), [], $this->root, $reduced);
      expect($legacy->textLayers)->toBeEmpty()->and($legacy->composites)->toBeEmpty();
    }
    $playback->update(10);
    $playback->update(10);
    expect($hits)->toBe(1)->and($playback->isCompleted)->toBeTrue()
      ->and($plan->getCinematicStageFrame($playback->session->currentFrame, $reduced))->toBeNull()
      ->and(array_column(array_filter($cues, static fn(array $cue): bool => $cue['type'] === 'commandPhase'), 'id'))
      ->not->toContain('command-summon-in', 'command-summon-title', 'command-summon-out')
      ->and(array_column($cues, 'id'))->toContain('contact', 'restore');
  })->with([false, true]);

it('pauses and cancels the existing stage clock without firing an unhit outcome', function () {
  $compiled = $this->compiler->compile(SummonCutsceneDefinition::fromArrays($this->data, $this->paired));
  $plan = new BattleCommandTimeline($this->timings, target: $compiled);
  $actor = new Character('Synthetic Actor', 1, new Stats());
  $hits = 0;
  $playback = new BattleCommandPlayback($plan, $actor, [$actor], BattlePoseRole::SUMMON,
    static function () use (&$hits): void { $hits++; }, static fn() => null);
  $tick = $plan->getCommandFrameForAuthoredFrame('target', 2);
  $playback->update($tick / BattleCommandTimeline::FPS);
  $playback->pause();
  $playback->update(10);
  expect($playback->session->currentFrame)->toBe($tick)->and($hits)->toBe(0);
  $playback->cancel();
  $playback->resume();
  $playback->update(10);
  expect($playback->isCancelled)->toBeTrue()->and($playback->getActiveSegments())->toBeEmpty()->and($hits)->toBe(0);
});

it('keeps terminal legacy fades independent when the unselected stage art is unavailable', function () {
  unlink($this->root . '/sheet.png');
  $definition = SummonCutsceneDefinition::fromArrays($this->data, $this->paired);
  $compiled = $this->compiler->compile($definition, EffectPresentation::TERMINAL);
  expect($compiled->transitionCache['in']['durationMs'])->toBe(500)
    ->and($compiled->defaults)->not->toHaveKey('stage')
    ->and(fn() => $this->compiler->compile($definition))->toThrow(RuntimeException::class);
});

it('replaces old or wrong-presentation serialized caches without writing authored sources', function (string $staleKind) {
  $directory = $this->root . '/Summons/synthetic-cover';
  mkdir($directory, 0700, true);
  $dataSource = '<?php /* source data */ return ' . var_export($this->data, true) . ';';
  $timelineSource = '<?php /* outer metadata only */ return ' . var_export($this->paired, true) . ';';
  file_put_contents($directory . '/synthetic-cover.data.php', $dataSource);
  file_put_contents($directory . '/synthetic-cover.timeline.php', $timelineSource);
  $definition = SummonCutsceneDefinition::fromArrays($this->data, $this->paired);
  $fresh = $this->compiler->compile($definition);
  $stale = $staleKind === 'wrong lane'
    ? $this->compiler->compile($definition, EffectPresentation::TERMINAL)->toArray() : $fresh->toArray();
  if ($staleKind === 'old version') { $stale['compileVersion'] = 3; }
  if ($staleKind === 'legacy duration') { $stale['transitionCache']['in']['durationMs'] = 500; }
  $cacheSource = '<?php return ' . var_export($stale, true) . ';';
  file_put_contents($directory . '/synthetic-cover.compiled.php', $cacheSource);
  $library = new SummonCutsceneLibrary($this->root . '/Summons', cacheForBattle: true, assetRoot: $this->root);
  $graphic = $library->loadCompiledOrCompileByLinkedActionId('synthetic-action');
  $terminal = $library->loadCompiledOrCompile('synthetic-cover', EffectPresentation::TERMINAL);
  expect($graphic->toArray())->toBe($fresh->toArray())
    ->and($graphic->compileVersion)->toBeGreaterThan(3)
    ->and($library->loadCompiledOrCompile('synthetic-cover'))->toBe($graphic)
    ->and($terminal->transitionCache['in']['durationMs'])->toBe(500)
    ->and($terminal->defaults['presentation'])->toBe('terminal')
    ->and(file_get_contents($directory . '/synthetic-cover.data.php'))->toBe($dataSource)
    ->and(file_get_contents($directory . '/synthetic-cover.timeline.php'))->toBe($timelineSource)
    ->and(file_get_contents($directory . '/synthetic-cover.compiled.php'))->toBe($cacheSource);
})->with(['old version', 'wrong lane', 'legacy duration']);

it('revalidates current synthetic sheet dimensions without changing authored placement or cover ownership', function () {
  $definition = SummonCutsceneDefinition::fromArrays($this->data, $this->paired);
  $first = $this->compiler->compile($definition);
  // Atomic replacement exercises inode changes; shared preflight tests separately cover in-place stat collisions.
  writeTestPng($this->root . '/replacement.png', 32, 16);
  rename($this->root . '/replacement.png', $this->root . '/sheet.png');
  $next = $this->compiler->compile($definition);
  $firstDraw = $first->playbackSegments[0]['drawCommands'][0];
  $nextDraw = $next->playbackSegments[0]['drawCommands'][0];
  expect($firstDraw['payload']['frameWidth'])->toBe(8)
    ->and($nextDraw['payload']['frameWidth'])->toBe(16)
    ->and($nextDraw['payload']['frameHeight'])->toBe(16)
    ->and($nextDraw['payload']['placement'])->toBe($firstDraw['payload']['placement'])
    ->and($next->transitionCache)->toBe($first->transitionCache)
    ->and($next->sourceHash)->toBe($first->sourceHash)
    ->and($definition->toTimelineArray())->toBe($this->paired);
});

it('keeps paired format and editor metadata outer-only instead of silently accepting nested standalone documents',
  function (string $key, mixed $value) {
    $this->paired['presentations']['graphical'][$key] = $value;
    $definition = SummonCutsceneDefinition::fromArrays($this->data, $this->paired);
    expect(fn() => $this->compiler->compile($definition))->toThrow(InvalidArgumentException::class);
  })->with([['formatVersion', 1], ['editor', ['notes' => 'nested']]]);
