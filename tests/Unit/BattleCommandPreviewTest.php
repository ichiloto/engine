<?php

use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\BattleRewards;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPreview;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneCompiler;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\CharacterSprites;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-command-preview-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  writeTestPng($this->root . '/arena.png', 40, 30);
  writeTestPng($this->root . '/body.png', 8, 16);
  writeTestPng($this->root . '/effect.png', 32, 16);
  $this->actor = new Character('Preview Hero', 1, new Stats(currentHp: 100, totalHp: 100,
    currentMp: 40, totalMp: 40), new CharacterSprites(battle: ['O', '|', 'V']));
  $party = new Party();
  $party->addMember($this->actor);
  $this->target = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
  foreach (['name' => 'Preview Target', 'level' => 1, 'stats' => new Stats(currentHp: 100, totalHp: 100),
    'position' => new Vector2(10, 10), 'image' => ['<X>'], 'imagePath' => '',
    'rewards' => new BattleRewards(0, 0, [])] as $key => $value) {
    new ReflectionProperty(Enemy::class, $key)->setValue($this->target, $value);
  }
  $this->battle = new BattleConfig($party, new Troop('Preview troop', [$this->target],
    graphicalFormation: [new BattlerSlot(100, 200, 48, 96)]));
  $art = new BattlerArtwork('body.png', 8, 16, 4, 16);
  $this->catalog = new BattlePresentationCatalog([
    'preview' => new BattleArenaDefinition('Preview',
      new CanvasImage('arena', 'arena.png', new CanvasRectangle(0, 0, 400, 300))),
  ], ['Preview Hero' => $art], ['Preview Target' => $art],
    ui: new BattleCanvasLayout(400, 300, uiCellWidth: 2, uiCellHeight: 4,
      partySlots: [new BattlerSlot(320, 200, 48, 96)]),
    defaultArena: 'preview');
  $definition = SummonCutsceneDefinition::fromArrays(['id' => 'preview-call', 'name' => 'Preview Call',
    'effectTiming' => ['mode' => 'cue', 'cueId' => 'contact']], [
    'fps' => 8, 'lengthFrames' => 4, 'restFrame' => 2, 'tracks' => [[
      'id' => 'body', 'type' => 'image', 'presentation' => 'graphical',
      'asset' => 'effect.png', 'sheet' => ['columns' => 2, 'rows' => 1],
      'cells' => ['width' => 2, 'height' => 2], 'anchor' => 'caster', 'attachment' => 'ground',
      'pivot' => ['x' => .5, 'y' => 1], 'facing' => 'west',
      'keyframes' => [['frame' => 0, 'duration' => 2, 'sourceFrame' => 0],
        ['frame' => 2, 'duration' => 2, 'sourceFrame' => 1]],
    ], ['id' => 'terminal', 'type' => 'glyph', 'presentation' => 'terminal', 'anchor' => 'target',
      'keyframes' => [['frame' => 2, 'duration' => 2, 'content' => '*', 'position' => [0, 0]]]]],
    'cues' => [['id' => 'contact', 'frame' => 2, 'type' => 'applyEffect']],
  ]);
  $compiler = new SummonCutsceneCompiler(assetRoot: $this->root);
  $this->definition = $definition;
  $compiled = $compiler->compile($definition);
  $this->plan = new BattleCommandTimeline(new BattleTurnTimings(.2, .2, .2, .2, .2, .2, .2),
    target: $compiled, terminalTarget: $compiler->compile($definition, EffectPresentation::TERMINAL));
  $this->preview = new BattleCommandPreview($this->battle, $this->plan, $this->actor, [$this->target],
    BattlePoseRole::MAGIC, $this->catalog, $this->root);
});

afterEach(function () {
  foreach (glob($this->root . '/*') as $file) { unlink($file); }
  rmdir($this->root);
});

it('previews real summon atlas layers and cues deterministically without combat mutation', function () {
  $frame = $this->plan->phases['target']['start'] + 30;
  $before = [$this->actor->stats->currentHp, $this->actor->stats->currentMp, $this->target->stats->currentHp];
  $first = $this->preview->getFrameAtIndex($frame);
  $this->preview->getFrameAtIndex($this->preview->totalFrames - 1);
  $again = $this->preview->getFrameAtTime($frame / BattleCommandTimeline::FPS);
  expect($first->toArray())->toBe($again->toArray())->and($first->phase)->toBe('target')
    ->and(array_column($first->cues, 'id'))->toContain('contact', 'command-impact')
    ->and(array_column($first->crossedCues, 'id'))->toContain('contact')
    ->and([$this->actor->stats->currentHp, $this->actor->stats->currentMp, $this->target->stats->currentHp])->toBe($before)
    ->and(implode("\n", $first->terminalLines))->toContain('*');
  $image = array_find($first->canvas->images, static fn($image): bool => $image->asset === 'effect.png');
  expect($image)->not->toBeNull()->and($image->sourceRect->x)->toBe(16)
    ->and($first->diagnostics)->toBeEmpty();
});

it('replaces the arena during an owned stage and restores it exactly on seek or cancellation', function (bool $foregroundContinues) {
  $source = $this->definition->toTimelineArray();
  $source['stage'] = ['canvas' => ['width' => 400, 'height' => 300],
    'startFrame' => 0, 'restoreFrame' => 3,
    'camera' => [['id' => 'initial', 'frame' => 0, 'focus' => ['x' => 200, 'y' => 150], 'zoom' => 1]],
    'subjects' => [['id' => 'visitor', 'position' => ['x' => 200, 'y' => 250],
      'size' => ['width' => 96, 'height' => 96], 'attachments' => []]]];
  $source['tracks'][0] = ['id' => 'body', 'type' => 'image', 'asset' => 'effect.png',
    'anchor' => 'stage', 'placement' => ['subject' => 'visitor', 'size' => ['width' => 96, 'height' => 96]],
    'pivot' => ['x' => .5, 'y' => 1], 'keyframes' => [['frame' => 0, 'duration' => $foregroundContinues ? 4 : 3]]];
  $compiled = (new SummonCutsceneCompiler(assetRoot: $this->root))
    ->compile(SummonCutsceneDefinition::fromArrays($this->definition->toDataArray(), $source));
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.2, .2, .2, .2, .2, .2, .2), target: $compiled);
  $preview = new BattleCommandPreview($this->battle, $plan, $this->actor, [$this->target],
    BattlePoseRole::MAGIC, $this->catalog, $this->root);
  $active = $preview->getFrameAtIndex($plan->getCommandFrameForAuthoredFrame('target', 1));
  expect(array_column($active->canvas->images, 'id'))->toBe(['cinematic-stage-body'])
    ->and($active->diagnostics)->toBeEmpty();
  $restored = $preview->getFrameAtIndex($plan->getCommandFrameForAuthoredFrame('target', 3));
  $idle = GraphicalBattlePresentation::prepare($this->battle, $this->catalog, $this->root)->frame(now: 0);
  expect($restored->canvas->images[0]->toArray())->toBe($idle->images[0]->toArray())
    ->and(array_column($restored->canvas->images, 'id'))->toBe([
      ...array_column($idle->images, 'id'), ...($foregroundContinues ? ['cinematic-stage-body'] : [])])
    ->and($restored->canvas->images[2]->toArray())->toBe($idle->images[2]->toArray())
    ->and($preview->getFrameAtIndex($plan->getCommandFrameForAuthoredFrame('target', 1))->toArray())->toBe($active->toArray());
  $playback = new BattleCommandPlayback($plan, $this->actor, [$this->target], BattlePoseRole::MAGIC,
    static fn() => null, static fn() => null);
  $playback->begin();
  $playback->update($plan->getCommandFrameForAuthoredFrame('target', 3) / BattleCommandTimeline::FPS);
  $playback->cancel();
  $cancelled = GraphicalBattlePresentation::prepare($this->battle, $this->catalog, $this->root)
    ->frame(new BattlePresentationSnapshot($playback), now: 0);
  expect($cancelled->toArray())->toBe($idle->toArray())
    ->and($this->actor->stats->currentHp)->toBe(100)->and($this->target->stats->currentHp)->toBe(100);
})->with(['foreground ends with backdrop' => false, 'foreground continues over live arena' => true]);

it('uses the production canvas composer at the same phase and playhead', function () {
  $frame = $this->plan->phases['target']['start'];
  $playback = new BattleCommandPlayback($this->plan, $this->actor, [$this->target], BattlePoseRole::MAGIC,
    static function (): void {}, static function (): void {});
  $playback->begin();
  $playback->update($frame / BattleCommandTimeline::FPS);
  $actual = GraphicalBattlePresentation::prepare($this->battle, $this->catalog, $this->root)
    ->frame(new BattlePresentationSnapshot($playback, poseElapsedSeconds: $frame / BattleCommandTimeline::FPS),
      now: $frame / BattleCommandTimeline::FPS, reducedMotion: false);
  expect($this->preview->getFrameAtIndex($frame)->canvas->toArray())->toBe($actual->toArray());
});

it('exports a styled Terminal canvas while preserving terminal source lines and combat state', function () {
  new ReflectionProperty(Enemy::class, 'image')->setValue($this->target, ["\e[1;33m<X>\e[0m"]);
  $frame = $this->preview->getFrameAtIndex(0);
  $canvas = $frame->terminalCanvas;
  expect($canvas)->not->toBeNull()->and($canvas->textLayers)->toHaveCount(1)
    ->and($canvas->textLayers[0]->grid->columns)->toBe(135)
    ->and($canvas->textLayers[0]->grid->rows)->toBe(30)
    ->and(implode("\n", $frame->terminalLines))->toContain("\e[")
    ->and(array_any($canvas->textLayers[0]->runs, static fn($run) => $run->foreground?->toArray()
      === ['kind' => 'ansi16', 'index' => 11]))->toBeTrue()
    ->and($frame->toArray()['terminalCanvas'])->toBe($canvas->toArray())
    ->and($this->actor->stats->currentMp)->toBe(40)->and($this->target->stats->currentHp)->toBe(100);
  foreach ($canvas->textLayers[0]->runs as $run) { expect($run->text)->not->toContain("\e"); }
});

it('holds the authored reduced-motion rest art without changing phase or cue timing', function () {
  $frame = $this->plan->phases['target']['start'];
  $normal = $this->preview->getFrameAtIndex($frame);
  $reduced = $this->preview->getFrameAtIndex($frame, true);
  $image = array_find($reduced->canvas->images, static fn($image): bool => $image->asset === 'effect.png');
  expect($image->sourceRect->x)->toBe(16)->and($reduced->phase)->toBe($normal->phase)
    ->and($reduced->crossedCues)->toBe($normal->crossedCues);
});

it('keeps legacy Terminal screen placement identical in paired and Terminal-only summon previews', function () {
  $source = $this->definition->toTimelineArray();
  unset($source['tracks'][1]['anchor']);
  $source['tracks'][1]['keyframes'][0]['position'] = ['x' => 3, 'y' => 4];
  $definition = SummonCutsceneDefinition::fromArrays($this->definition->toDataArray(), $source);
  $compiler = new SummonCutsceneCompiler(assetRoot: $this->root);
  $graphical = $compiler->compile($definition);
  $terminal = $compiler->compile($definition, EffectPresentation::TERMINAL);
  $timings = new BattleTurnTimings(.2, .2, .2, .2, .2, .2, .2);
  $pairedPlan = new BattleCommandTimeline($timings, target: $graphical, terminalTarget: $terminal);
  $terminalPlan = new BattleCommandTimeline($timings, target: $terminal);
  $paired = new BattleCommandPreview($this->battle, $pairedPlan, $this->actor, [$this->target],
    BattlePoseRole::SUMMON, $this->catalog, $this->root);
  $standalone = new BattleCommandPreview($this->battle, $terminalPlan, $this->actor, [$this->target],
    BattlePoseRole::SUMMON, presentation: EffectPresentation::TERMINAL);
  $frame = $pairedPlan->phases['target']['start'] + 30;
  expect($paired->getFrameAtIndex($frame)->terminalLines)->toBe($standalone->getFrameAtIndex($frame)->terminalLines)
    ->and($pairedPlan->terminalSegments[0]['drawCommands'][0]['payload']['anchor'])->toBe('legacy-screen')
    ->and($terminal->playbackSegments[0]['drawCommands'][0]['payload'])->not->toHaveKey('anchor');
});

it('constructs Terminal previews without reading or requiring graphical assets', function (bool $reduced) {
  $source = $this->definition->toTimelineArray();
  $source = ['presentations' => ['graphical' => $source, 'terminal' => ['fps' => 11, 'lengthFrames' => 5, 'restFrame' => 3,
    'tracks' => [['id' => 'terminal-only', 'type' => 'glyph', 'anchor' => 'target',
      'keyframes' => [['frame' => 0, 'duration' => 5, 'content' => '+', 'position' => ['x' => 0, 'y' => 0]]]]],
    'cues' => [['id' => 'contact', 'frame' => 3, 'type' => 'applyEffect']]]]];
  $definition = SummonCutsceneDefinition::fromArrays($this->definition->toDataArray(), $source);
  foreach (glob($this->root . '/*.png') as $file) { unlink($file); }
  $compiled = new SummonCutsceneCompiler(assetRoot: $this->root)->compile($definition, EffectPresentation::TERMINAL);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.2, .2, .2, .2, .2, .2, .2), target: $compiled);
  $preview = new BattleCommandPreview($this->battle, $plan, $this->actor, [$this->target],
    BattlePoseRole::SUMMON, presentation: EffectPresentation::TERMINAL);
  $contact = $plan->getCommandFrameForAuthoredFrame('target', 3);
  $frame = $preview->getFrameAtIndex($contact, $reduced);
  expect($plan->phases['target']['length'])->toBe(55)
    ->and($frame->canvas)->toBeNull()->and($frame->diagnostics)->toBeEmpty()
    ->and($frame->authoredFrames)->toBe(['source' => null, 'target' => 3, 'terminal-target' => null])
    ->and($frame->toArray()['authoredFrames'])->toBe($frame->authoredFrames)
    ->and(implode("\n", $frame->terminalLines))->toContain('+')
    ->and($this->actor->stats->currentMp)->toBe(40)->and($this->target->stats->currentHp)->toBe(100);
  expect(array_column($preview->getFrameAtIndex($contact - 1, $reduced)->crossedCues, 'id'))->not->toContain('contact')
    ->and(array_column($frame->crossedCues, 'id'))->toContain('contact');
})->with([false, true]);

it('refuses a missing graphical catalog instead of inventing a Terminal fallback', function () {
  expect(fn() => new BattleCommandPreview($this->battle, $this->plan, $this->actor, [$this->target],
    BattlePoseRole::SUMMON))->toThrow(InvalidArgumentException::class,
      'A graphical battle preview requires its presentation catalog.');
});

it('refuses invalid playheads and foreign combatants without mutating the battle', function () {
  expect(fn() => $this->preview->getFrameAtIndex(-1))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $this->preview->getFrameAtIndex($this->preview->totalFrames))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $this->preview->getFrameAtTime(INF))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $this->preview->getFrameAtTime(-.1))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattleCommandPreview($this->battle, $this->plan, new Character('Foreign', 1, new Stats()),
      [$this->target], BattlePoseRole::MAGIC, $this->catalog, $this->root))->toThrow(InvalidArgumentException::class);
});
