<?php

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasUiAdapter;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\BattlePoseSet;
use Ichiloto\Engine\Battle\Presentation\BattlerPose;
use Ichiloto\Engine\Battle\Presentation\BattleScale;
use Ichiloto\Engine\Battle\Presentation\BattlerScale;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation;
use Ichiloto\Engine\Battle\Presentation\BattleHudSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleUiSkin;
use Ichiloto\Engine\Battle\Presentation\BattleTargetCursor;
use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Battle\Presentation\BattleRewards;
use Ichiloto\Engine\Battle\Presentation\BattleResultsSkin;
use Ichiloto\Engine\Progression\ExperienceAwarder;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Battle\Resolution\SeededCombatRandomSource;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

function graphicalBattleFixture(bool $skinned = false, bool $directionalCursor = false, float $partyDepth = 1.0): array
{
  $party = new Party();
  $hero = new Character('Hero', 1, new Stats(currentHp: 100, totalHp: 100, attack: 32));
  $party->addMember($hero);
  $enemies = [];
  foreach ([0, 1] as $index) {
    $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
    foreach (['name' => 'Twin', 'level' => 1, 'stats' => new Stats(currentHp: 100, totalHp: 100),
      'position' => new Vector2($index * 10 + 1, 1), 'image' => ['ASCII MUST NOT DRAW'], 'imagePath' => '', 'rewards' => new \Ichiloto\Engine\Battle\BattleRewards(0, 0, [])] as $key => $value) {
      new ReflectionProperty(Enemy::class, $key)->setValue($enemy, $value);
    }
    $enemies[] = $enemy;
  }
  $battle = new BattleConfig($party, new Troop('Twins', $enemies, graphicalFormation:
    [new BattlerSlot(375, 467, 173, 197), new BattlerSlot(497, 233, 197, 119)]), entryExecutionId: 'fixture-entry');
  $art = new BattlerArtwork('graphical-canvas/synthetic-143x181.png', 143, 181, 71.5, 181);
  $skin = null;
  if ($skinned) {
    $textures = array_fill_keys(['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued'],
      new CanvasNineSlice('test-sprite.png', new SpriteSourceRect(0, 0, 32, 48)));
    $colors = array_fill_keys(['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'],
      PresentationColor::rgb(200, 200, 200));
    $skin = new BattleUiSkin($textures, $colors, $directionalCursor
      ? new BattleTargetCursor(array_fill_keys(['above', 'left', 'right'],
        new CanvasNineSlice('test-sprite.png', new SpriteSourceRect(0, 0, 32, 32)))) : null);
  }
  $arena = new BattleArenaDefinition('Test scene',
    new CanvasImage('arena', 'graphical-canvas/synthetic-320x180.png', new CanvasRectangle(0, 0, 1350, 720)));
  $layout = new BattleCanvasLayout(1350, 720, skin: $skin,
    feedbackArea: new CanvasRectangle(0, 80, 1350, 452),
    partySlots: [new BattlerSlot(969, 265, 143, 181, $partyDepth), new BattlerSlot(1137, 383, 137, 179, $partyDepth),
      new BattlerSlot(969, 501, 151, 183, $partyDepth)]);
  return [$battle, new BattlePresentationCatalog(['arena.test' => $arena], ['Hero' => $art], ['Twin' => $art],
    ui: $layout, defaultArena: 'arena.test'), $hero, $enemies];
}

it('retains enemy artwork through feedback then pulses or fades and clears without render side effects', function (bool $reduced) {
  [$battle, $catalog, $hero, $enemies] = graphicalBattleFixture(true);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $enemy = $enemies[0];
  $events = [];
  $playback = null;
  $playback = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback(
    new \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline(new \Ichiloto\Engine\Battle\BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1)),
    $hero, [$enemy], BattlePoseRole::ATTACK, function () use ($enemy): void { $enemy->stats->currentHp = 0; },
    function ($cue) use (&$playback, $enemy, &$events): void {
      if ($cue['type'] === 'commandResolved') { $playback->beginEnemyDefeat($enemy); }
      if ($cue['type'] === 'enemyDefeated') { $events[] = $enemy; }
    });
  $field = new \Ichiloto\Engine\Battle\Presentation\BattlePresentationSnapshot($playback);
  $start = $playback->plan->phases['return']['start'] / 120;
  $playback->update($start + .01);
  $id = 'combatant-' . spl_object_id($enemy);
  $frame = $presentation->frame($field, reducedMotion: $reduced);
  $image = array_find($frame->images, static fn($image) => $image->id === $id);
  expect($image)->not->toBeNull()->and($events)->toBeEmpty();
  $tints = array_filter($frame->composites, static fn($tint) => str_starts_with($tint->id, 'enemy-defeat-'));
  expect($tints)->toHaveCount($reduced ? 0 : 1);
  if ($reduced) { expect($image->opacity)->toBeLessThan(1.0); }
  else {
    expect($image->opacity)->toBe(1.0);
    $tint = array_values($tints)[0];
    expect($tint->operations[0]->data['masks'][0]['type'])->toBe('image_alpha')
      ->and($tint->operations[0]->data['masks'][0]['asset'])->toBe($image->asset);
  }
  expect($presentation->frame($field, reducedMotion: $reduced)->toArray())->toBe($frame->toArray())
    ->and($events)->toBeEmpty();
  $style = $playback->defeatStyle;
  $playback->update($style->pulses * $style->pulseSeconds + $style->fadeSeconds / 2 - .01);
  $fading = array_find($presentation->frame($field, reducedMotion: $reduced)->images, static fn($image) => $image->id === $id);
  expect($fading->opacity)->toBeLessThan(1.0)->toBeGreaterThan(0.0)->and($playback->isCompleted)->toBeFalse();
  $playback->update(10);
  expect(array_find($presentation->frame($field, reducedMotion: $reduced)->images, static fn($image) => $image->id === $id))
    ->toBeNull()->and($events)->toBe([$enemy])->and($playback->isCompleted)->toBeTrue();
})->with([false, true]);

it('samples bound condition art from the actual field clock and freezes its reduced-motion rest', function (bool $reduced) {
  $root = createTestDirectory('live-graphical-condition');
  mkdir($root . '/graphical-canvas');
  \Tests\Support\Rendering\writeTestPng($root . '/graphical-canvas/synthetic-320x180.png', 320, 180);
  \Tests\Support\Rendering\writeTestPng($root . '/graphical-canvas/synthetic-143x181.png', 143, 181);
  \Tests\Support\Rendering\writeTestPng($root . '/condition.png', 8, 4);
  mkdir($root . '/Animations/condition-loop', 0777, true);
  file_put_contents($root . '/Animations/condition-loop/condition-loop.timeline.php', '<?php return ' . var_export([
    'fps' => 10, 'lengthFrames' => 2, 'restFrame' => 1, 'playback' => 'loop', 'tracks' => [[
      'id' => 'aura', 'type' => 'image', 'anchor' => 'target', 'attachment' => 'head',
      'asset' => 'condition.png', 'sheet' => ['columns' => 2, 'rows' => 1],
      'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]],
    ]]], true) . ';');
  ConfigStore::put(\Ichiloto\Engine\Util\Config\ProjectConfig::class, new PlaySettings([
    'ui' => ['battle' => ['conditions' => ['states' => ['synthetic' => 'condition-loop']]]]]));
  [$battle, $catalog, $hero] = graphicalBattleFixture();
  $hero->addState(new \Ichiloto\Engine\Entities\States\State('synthetic', 'State', 'S'));
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $root);
  $field = graphicalBattleScene($battle, $presentation)->ui->fieldWindow;
  $getImage = fn() => array_find($presentation->frame($field, reducedMotion: $reduced)->images,
    static fn($image) => str_starts_with($image->id, 'command-effect-condition-'));
  expect($getImage()->sourceRect->x)->toBe($reduced ? 4 : 0);
  $field->advancePoseTime(.1);
  expect($getImage()->sourceRect->x)->toBe(4);
  $field->pauseTiming();
  $field->advancePoseTime(100);
  expect($getImage()->sourceRect->x)->toBe(4);
  $field->resumeTiming();
  $field->advancePoseTime(.1);
  expect($getImage()->sourceRect->x)->toBe($reduced ? 4 : 0);
  $hero->removeState('synthetic');
  expect($getImage())->toBeNull();
})->with([false, true]);

it('projects live condition field fallbacks without adding HUD icons or retaining cured states', function (bool $reduced) {
  [$battle, $catalog, $hero] = graphicalBattleFixture(true);
  $hero->addState(new \Ichiloto\Engine\Entities\States\State('synthetic', 'Synthetic state', 'STATE', 'Explain this state.'));
  $hero->setStatStage('speed', -1);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $frame = $presentation->frame(reducedMotion: $reduced);
  $conditions = array_filter($frame->textLayers, static fn($layer) => str_starts_with($layer->id, 'status-badge-')
    && str_ends_with($layer->id, '-label'));
  $conditionLabels = array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), array_values($conditions)));
  expect($conditionLabels)->toBe(['SYN', '-1'])
    ->and(array_filter($frame->textLayers, static fn($layer) => str_starts_with($layer->id, 'command-effect-condition-')))->toBeEmpty()
    ->and($hero->hasState('synthetic'))->toBeTrue();
  $hero->removeState('synthetic');
  $hero->resetStatStages();
  expect(array_filter($presentation->frame(reducedMotion: $reduced)->textLayers,
    static fn($layer) => str_starts_with($layer->id, 'status-badge')))->toBeEmpty();
})->with([false, true]);

it('protects the actual battlers and not the full decorative arena for shared notification placement', function () {
  [$battle, $catalog] = graphicalBattleFixture();
  $canvas = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root)->frame();
  expect($canvas->protectedAreas)->not->toBeNull()
    ->and(\Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationPlacement::isClear(
      new CanvasRectangle(20, 20, 100, 80), $canvas->getOverlayProtection()))->toBeTrue();
  foreach ($canvas->images as $image) {
    if ($image->id === 'arena') { continue; }
    expect(\Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationPlacement::isClear(
      $image->destination, $canvas->getOverlayProtection()))->toBeFalse();
  }
});

it('keeps battle artwork attached to explicit actor identity after an authored display rename', function () {
  [$battle, $catalog, $hero] = graphicalBattleFixture();
  $data = (require dirname(__DIR__) . '/Fixtures/Actors/FoundationHero.php')['data'];
  $data['id'] = 'Hero';
  $definition = \Ichiloto\Engine\Entities\Actors\ActorDefinition::fromArray($data);
  $saved = $definition->createCharacter()->toArray();
  $data['name'] = 'Hero Renamed';
  $battle->party->members[0] = \Ichiloto\Engine\Entities\Actors\ActorDefinition::fromArray($data)->createCharacter($saved);
  $frame = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root)->frame();
  $identity = 'combatant-' . spl_object_id($battle->party->members[0]);
  $party = array_values(array_filter($frame->images, static fn($image) => $image->id === $identity));
  expect($battle->party->members[0]->actorId)->toBe('Hero')->and($battle->party->members[0]->name)->toBe('Hero Renamed')
    ->and($party)->not->toBeEmpty()->and($party[0]->asset)->toBe($catalog->actors['Hero']->asset);
});

function graphicalBattleScene(BattleConfig $battle, ?GraphicalBattlePresentation $presentation, ?BattleCanvasLayout $ui = null): BattleScene
{
  $scene = new ReflectionClass(BattleScene::class)->newInstanceWithoutConstructor();
  $game = graphicalBattleConfigurationScene(null)->getGame();
  new ReflectionProperty(Ichiloto\Engine\Scenes\SceneManager::class, 'game')->setValue($game->sceneManager, $game);
  new ReflectionProperty(AbstractScene::class, 'sceneManager')->setValue($scene, $game->sceneManager);
  new ReflectionProperty(BattleScene::class, 'config')->setValue($scene, $battle);
  new ReflectionProperty(BattleScene::class, 'graphicalPresentation')->setValue($scene, $presentation);
  new ReflectionProperty(BattleScene::class, 'battleUiLayout')->setValue($scene, $ui);
  new ReflectionProperty(AbstractScene::class, 'camera')->setValue($scene, new Camera($scene, 135, 36));
  $scene->ui = new BattleScreen($scene);
  return $scene;
}

function graphicalResultsFixtureSkin(): BattleResultsSkin
{
  return new BattleResultsSkin(array_fill_keys(['panel', 'quiet', 'track', 'selector', 'portrait', 'exp', 'divider', 'button'],
    new CanvasNineSlice('test-sprite.png', new SpriteSourceRect(0, 0, 32, 48))),
    array_fill_keys(['text', 'muted', 'accent', 'positive', 'negative', 'ink'], PresentationColor::rgb(220, 220, 220)));
}

function graphicalBattleConfigurationScene(?RendererRuntime $runtime): BattleScene
{
  $game = new class extends Ichiloto\Engine\Core\Game {
    public function __construct() {
      $this->sceneManager = new class extends Ichiloto\Engine\Scenes\SceneManager {
        public function __construct() { $this->scenes = new Assegai\Collections\ItemList(Ichiloto\Engine\Scenes\Interfaces\SceneInterface::class); }
      };
    }
    public function __destruct() {}
  };
  if ($runtime !== null) { $game->useRendererRuntime($runtime); }
  return new class($game) extends BattleScene {
    public function __construct(private Ichiloto\Engine\Core\Game $testGame) {}
    public function getGame(): Ichiloto\Engine\Core\Game { return $this->testGame; }
    // Configuration is under test, not the timed/input-driven transition.
    public function setState(Ichiloto\Engine\Scenes\Battle\States\BattleSceneState $state): void { $this->state = $state; }
  };
}

beforeEach(function () {
  $this->statics = [];
  foreach ([Console::class, ConfigStore::class, InputManager::class, Debug::class, Ichiloto\Engine\Core\Time::class,
    \Ichiloto\Engine\Battle\BattleCommandCatalog::class] as $class) { $this->statics[$class] = new ReflectionClass($class)->getStaticProperties(); }
  $this->logRoot = sys_get_temp_dir() . '/ichiloto-battle-logs-' . bin2hex(random_bytes(5));
  Debug::configure(['log_directory' => $this->logRoot]);
  new ReflectionProperty(Console::class, 'terminalHandedBack')->setValue(null, false);
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 36);
  Console::setLayerTracking(true);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  ConfigStore::put(Ichiloto\Engine\Util\Config\ProjectConfig::class, new PlaySettings([]));
  $this->root = __DIR__ . '/../Fixtures/Renderer';
});

final class GraphicalCommandFixtureAction extends \Ichiloto\Engine\Battle\BattleAction
{
  public int $executions = 0;
  public int $hpChange = -7;
  public function execute(\Ichiloto\Engine\Entities\Interfaces\CharacterInterface $actor, array $targets): void
  {
    $this->executions++;
    foreach ($targets as $target) { $target->stats->currentHp += $this->hpChange; }
  }
}

function graphicalCommandRuntime(bool $atb, bool $reduced = false, bool $enemyActs = false, ?BattlePoseSet $poses = null): array
{
  ConfigStore::put(Ichiloto\Engine\Util\Config\ProjectConfig::class,
    new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  [$battle, $catalog, $hero, $enemies] = graphicalBattleFixture();
  $pose = new \Ichiloto\Engine\Battle\Presentation\BattlerPose('test-sprite.png', columns: 2, frames: [0, 1], fps: 8);
  $poses ??= new \Ichiloto\Engine\Battle\Presentation\BattlePoseSet(array_fill_keys(['idle', 'skill', 'damage', 'heal', 'knockout'], $pose));
  $catalog = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, $catalog->enemies, ui: $catalog->ui, defaultArena: $catalog->defaultArena,
    actorPoses: ['Hero' => $poses], enemyPoses: ['Twin' => $poses]);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, __DIR__ . '/../Fixtures/Renderer');
  $scene = graphicalBattleScene($battle, $presentation);
  $scene->ui = new class($scene) extends BattleScreen {
    public bool $failRedraw = false;
    public function refreshField(): void {
      if ($this->failRedraw) { throw new RuntimeException('effect redraw offline'); }
      parent::refreshField();
    }
  };
  $game = $scene->getGame();
  $engine = $atb ? new \Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine($game)
    : new \Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine($game);
  $config = $atb ? new \Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleConfig($battle->party, $battle->troop, $scene->ui)
    : new \Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedBattleConfig($battle->party, $battle->troop, $scene->ui);
  $engine->configure($config);
  $game->setBattleEngine($engine);
  $context = new \Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext(
    $game, $battle->party, $battle->troop, $scene->ui, []);
  new ReflectionProperty(\Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedEngine::class, 'turnStateExecutionContext')->setValue($engine, $context);
  $action = new GraphicalCommandFixtureAction('Fixture');
  $turn = new \Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn($enemyActs ? $enemies[0] : $hero);
  $turn->action = $action;
  $turn->targets = [$enemyActs ? $hero : $enemies[0]];
  $context->setTurns([$turn]);
  $engine->setState($engine->actionExecutionState);
  return [$engine, new \Ichiloto\Engine\Battle\Engines\BattleEngineContext($game, $battle->party, $battle->troop, $scene->ui),
    $scene, $presentation, $action, $turn, $context];
}

function graphicalCompletePoseSet(): BattlePoseSet
{
  $roles = [];
  foreach (['idle', 'attack', 'skill', 'magic', 'item', 'guard', 'afflicted', 'enhanced', 'knockout', 'summon', 'damage', 'heal'] as $index => $role) {
    $roles[$role] = new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 4, rows: 3,
      frames: [$index], restFrame: $index, pivotY: .9);
  }
  return new BattlePoseSet($roles, displayWidth: 143);
}

it('removes acting markers and labels while preserving command advance and return', function (bool $skinned, bool $party, bool $reduced, float $partyDepth) {
  ConfigStore::put(Ichiloto\Engine\Util\Config\ProjectConfig::class,
    new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  [$battle, $catalog, $hero, $enemies] = graphicalBattleFixture(skinned: $skinned, partyDepth: $partyDepth);
  $poses = new BattlePoseSet([
    'idle' => new BattlerPose('graphical-canvas/synthetic-143x181.png', pivotX: .3, pivotY: .75),
    'attack' => new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 2, frames: [0, 1], pivotX: .8, pivotY: .65),
  ]);
  $catalog = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, $catalog->enemies,
    ui: $catalog->ui, defaultArena: $catalog->defaultArena,
    actorPoses: ['Hero' => $poses], enemyPoses: ['Twin' => $poses]);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $field = graphicalBattleScene($battle, $presentation)->ui->fieldWindow;
  $actor = $party ? $hero : $enemies[0];
  $slot = $party ? $catalog->ui->partySlots[0] : $battle->troop->getGraphicalSlot($actor);
  $reference = $party ? $catalog->actors['Hero'] : $catalog->enemies['Twin'];
  $base = $slot->place($reference);
  $id = 'combatant-' . spl_object_id($actor);
  $resting = array_find($presentation->frame($field)->images, static fn($image) => $image->id === $id)->destination;
  $playback = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback(
    new \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline(
      new \Ichiloto\Engine\Battle\BattleTurnTimings(.2, .3, .3, .3, .3, .3, .3)),
    $actor, [], BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
  $field->setCommandPlayback($playback);
  $assertFrame = function () use ($field, $presentation, $actor, $slot, $base, $reference, $poses, $party, $reduced, $playback, $id): void {
    $canvas = $presentation->frame($field);
    expect(array_filter([...$canvas->images, ...$canvas->indicators],
      static fn($layer) => str_starts_with($layer->id, 'acting-')))->toBe([]);
    foreach ($canvas->textLayers as $layer) {
      expect(array_column($layer->runs, 'text'))->not->toContain('Acting');
    }
    $bounds = array_find($canvas->images, static fn($image) => $image->id === $id)->destination;
    $role = $playback->getPoseRole($actor);
    $pose = $poses->getPose($role)->getArtwork($this->root,
      $role === BattlePoseRole::IDLE ? 0 : $playback->getPoseElapsedSeconds($actor), $reduced);
    $posed = $slot->placeAtScale($pose, $base->width / $reference->width / $slot->displayScale);
    $advance = $reduced ? 0 : ($party ? -1 : 1) * $slot->width * .25 * $playback->getAdvanceFraction();
    expect($bounds->x)->toEqualWithDelta($posed->x + $advance, .000001)
      ->and($bounds->y)->toEqualWithDelta($posed->y, .000001)
      ->and($bounds->width)->toEqualWithDelta($posed->width, .000001)
      ->and($bounds->height)->toEqualWithDelta($posed->height, .000001);
  };
  $assertFrame();
  foreach ([.1, .15, .4, .4, .3, .25, .5] as $delta) {
    $playback->update($delta);
    $assertFrame();
  }
  expect($playback->isCompleted)->toBeTrue();
  $field->setCommandPlayback(null);
  $canvas = $presentation->frame($field);
  expect(array_find($canvas->images, static fn($image) => $image->id === $id)->destination)->toEqual($resting)
    ->and($actor->stats->currentHp)->toBe(100);
})->with([false, true])->with([false, true])->with([false, true])->with([1.0, 1.05]);

it('does not replace removed acting markers with text when battler artwork is unavailable', function (bool $skinned) {
  [$battle, $catalog, $hero] = graphicalBattleFixture(skinned: $skinned);
  $catalog = new BattlePresentationCatalog($catalog->arenas, [], $catalog->enemies,
    ui: $catalog->ui, defaultArena: $catalog->defaultArena);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $field = graphicalBattleScene($battle, $presentation)->ui->fieldWindow;
  $field->stepPartyBattlerForward($hero, 0);
  $canvas = $presentation->frame($field);
  $labels = array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), $canvas->textLayers));
  expect($labels)->toContain('Hero')->not->toContain('Acting')
    ->and(array_filter([...$canvas->images, ...$canvas->indicators],
      static fn($layer) => str_starts_with($layer->id, 'acting-')))->toBe([]);
})->with([false, true]);

it('inherits one reference body scale across every pose and sheet frame for either side', function (bool $party, bool $reduced, ?float $displayWidth, float $partyDepth, bool $referenceScale) {
  ConfigStore::put(Ichiloto\Engine\Util\Config\ProjectConfig::class,
    new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  [$battle, $catalog, $hero, $enemies] = graphicalBattleFixture(partyDepth: $partyDepth);
  $roles = [];
  foreach (BattlePoseRole::cases() as $index => $role) {
    $roles[$role->value] = match ($index % 3) {
      0 => new BattlerPose('graphical-canvas/synthetic-143x181.png', pivotX: .4, pivotY: .9),
      1 => new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 2, frames: [0, 1], restFrame: 1,
        pivotX: .7, pivotY: .9),
      2 => new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 4, rows: 3, frames: [0, 1], restFrame: 1,
        pivotX: .6, pivotY: .75),
    };
  }
  $set = new BattlePoseSet($roles, $referenceScale ? null : $displayWidth);
  $catalog = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, $catalog->enemies,
    ui: $catalog->ui, defaultArena: $catalog->defaultArena,
    actorPoses: ['Hero' => $set], enemyPoses: ['Twin' => $set],
    scale: $referenceScale ? new BattleScale('Hero', 100, ['Hero' => new BattlerScale(1, .75)],
      ['Twin' => new BattlerScale(.5, .75)]) : null);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $field = graphicalBattleScene($battle, $presentation)->ui->fieldWindow;
  $battler = $party ? $hero : $enemies[0];
  $reference = $party ? $catalog->actors['Hero'] : $catalog->enemies['Twin'];
  $slot = $party ? $catalog->ui->partySlots[0] : $battle->troop->getGraphicalSlot($battler);
  $baseScale = $displayWidth === null ? min($slot->width / $reference->width, $slot->height / $reference->height)
    : $displayWidth / $reference->width;
  foreach (BattlePoseRole::cases() as $role) {
    $plan = new \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline(
      new \Ichiloto\Engine\Battle\BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1));
    $playback = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback($plan, $battler, [], $role,
      static fn() => null, static fn() => null);
    $field->setCommandPlayback($playback);
    $playback->update(.11);
    $image = array_find($presentation->frame($field)->images,
      fn($image) => $image->id === 'combatant-' . spl_object_id($battler));
    $pose = $set->getPose($role);
    $art = $pose->getArtwork($this->root,
      $role === BattlePoseRole::IDLE ? $field->getPoseElapsedSeconds() : $playback->getPoseElapsedSeconds($battler), $reduced);
    $scale = ($referenceScale ? 100 * ($party ? 1 : .5) / (.75 * $art->height) : $baseScale) * $slot->displayScale;
    $advance = $reduced ? 0 : ($party ? -1 : 1) * $slot->width * .25;
    $recoil = $slot->width * $playback->getRecoilFraction($battler, $reduced);
    expect($image->asset)->toBe($art->asset)->and($image->sourceRect)->toEqual($art->sourceRect)
      ->and($image->destination->width / $art->width)->toEqualWithDelta($scale, .000001)
      ->and($image->destination->height / $art->height)->toEqualWithDelta($scale, .000001)
      ->and($image->destination->x + $art->pivotX * $scale)->toEqualWithDelta($slot->x + $advance + $recoil, .000001)
      ->and($image->destination->y + $art->pivotY * $scale)->toEqualWithDelta($slot->y, .000001)
      ->and($battler->stats->currentHp)->toBe(100);
  }
})->with([false, true])->with([false, true])->with([null, 100.0])->with([1.0, 1.05])->with([false, true]);

it('uses shared body proportions independently of formation envelopes and reference participation', function (bool $reduced, bool $referencePresent, float $partyDepth) {
  ConfigStore::put(Ichiloto\Engine\Util\Config\ProjectConfig::class,
    new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  [$battle, $catalog, $hero, $enemies] = graphicalBattleFixture(partyDepth: $partyDepth);
  $actors = $catalog->actors;
  if (!$referencePresent) {
    $other = new Character('Other', 1, new Stats(currentHp: 100, totalHp: 100));
    $battle->party->members[0] = $other;
    $actors['Other'] = $actors['Hero'];
    $hero = $other;
  }
  $scale = new BattleScale('Hero', 100, actors: [
    'Hero' => new BattlerScale(1, .75), 'Other' => new BattlerScale(1.1, .75),
  ], enemies: ['Twin' => new BattlerScale(.5, .5, horizontal: true)]);
  $catalog = new BattlePresentationCatalog($catalog->arenas, $actors, $catalog->enemies,
    ui: $catalog->ui, defaultArena: $catalog->defaultArena, scale: $scale);
  $frame = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root)->frame();
  $image = array_find($frame->images, fn($image) => $image->id === 'combatant-' . spl_object_id($hero));
  expect($image->destination->height * .75)->toEqualWithDelta(($referencePresent ? 100 : 110) * $partyDepth, .000001);
  foreach ($enemies as $enemy) {
    $image = array_find($frame->images, fn($image) => $image->id === 'combatant-' . spl_object_id($enemy));
    expect($image->destination->width * .5)->toEqualWithDelta(50, .000001)
      ->and($enemy->stats->currentHp)->toBe(100);
  }
})->with([false, true])->with([false, true])->with([1.0, 1.05]);

it('keeps the reference body unit across replacement resolutions and registered animated pose cells', function (bool $reduced, float $partyDepth) {
  ConfigStore::put(Ichiloto\Engine\Util\Config\ProjectConfig::class,
    new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  [$battle, $catalog, $hero] = graphicalBattleFixture(partyDepth: $partyDepth);
  $pose = new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 4, rows: 3,
    frames: [0, 1], restFrame: 1, pivotX: .4, pivotY: .9, scaleSpan: 2.25);
  $set = new BattlePoseSet(['attack' => $pose, 'knockout' => $pose]);
  $scale = new BattleScale('Hero', 90,
    actors: ['Hero' => new BattlerScale(1, .75)],
    enemies: ['Twin' => new BattlerScale(.5, .5, horizontal: true)]);
  $catalog = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, $catalog->enemies,
    ui: $catalog->ui, defaultArena: $catalog->defaultArena, actorPoses: ['Hero' => $set], scale: $scale);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $field = graphicalBattleScene($battle, $presentation)->ui->fieldWindow;
  $playback = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback(
    new \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline(
      new \Ichiloto\Engine\Battle\BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1)),
    $hero, [], BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
  $field->setCommandPlayback($playback);
  $playback->update(.11);
  foreach ([0, .1] as $delta) {
    $playback->update($delta);
    $image = array_find($presentation->frame($field)->images,
      fn($image) => $image->id === 'combatant-' . spl_object_id($hero));
    expect($image->destination->height * 2.25)->toEqualWithDelta(90 * $partyDepth, .000001)
      ->and($image->destination->width / $image->sourceRect->width)
      ->toEqualWithDelta($image->destination->height / $image->sourceRect->height, .000001);
  }
  $field->setCommandPlayback(null);
  $hero->stats->currentHp = 0;
  $image = array_find($presentation->frame($field)->images,
    fn($image) => $image->id === 'combatant-' . spl_object_id($hero));
  expect($image->destination->height * 2.25)->toEqualWithDelta(90 * $partyDepth, .000001)
    ->and($image->opacity)->toBe(1.0);
})->with([false, true])->with([1.0, 1.05]);

it('orients source and target strokes and ground attachments independently of asymmetric pose canvas centres', function (bool $enemyActs, bool $reduced, float $pivotX, bool $referenceScale, bool $ground) {
  ConfigStore::put(Ichiloto\Engine\Util\Config\ProjectConfig::class,
    new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
  [$battle, $catalog, $hero, $enemies] = graphicalBattleFixture();
  $layout = new BattleCanvasLayout(1350, 720,
    partySlots: [new BattlerSlot(450, 265, 143, 181)]);
  $poses = new BattlePoseSet([
    'idle' => new BattlerPose('graphical-canvas/synthetic-143x181.png'),
    'attack' => new BattlerPose('graphical-canvas/synthetic-320x180.png', columns: 2, frames: [0, 1],
      fps: 10, pivotX: $pivotX, pivotY: .9),
  ], displayWidth: $referenceScale ? null : 143);
  $catalog = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, $catalog->enemies,
    actorPoses: ['Hero' => $poses], enemyPoses: ['Twin' => $poses], ui: $layout, defaultArena: $catalog->defaultArena,
    scale: $referenceScale ? new BattleScale('Hero', 100,
      ['Hero' => new BattlerScale(1, .75)], ['Twin' => new BattlerScale(.6, .75)]) : null);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $field = graphicalBattleScene($battle, $presentation)->ui->fieldWindow;
  $effect = new \Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary($this->root)->compile('stroke',
    ['fps' => 10, 'lengthFrames' => 2, 'restFrame' => 1, 'tracks' => [[
      'id' => 'stroke', 'type' => 'image', 'asset' => 'test-sprite.png', 'facing' => 'east',
      ...($ground ? ['attachment' => 'ground', 'pivot' => ['x' => .2, 'y' => .75]] : []),
      'keyframes' => [['frame' => 0, 'position' => ['x' => 1, 'y' => 0]],
        ['frame' => 1, 'position' => ['x' => 1, 'y' => 0], 'flipX' => true, 'flipY' => $ground]],
    ]]], true);
  $actor = $enemyActs ? $enemies[0] : $hero;
  $target = $enemyActs ? $hero : $enemies[0];
  $hits = 0;
  $playback = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback(
    new \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline(
      new \Ichiloto\Engine\Battle\BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), $effect, $effect),
    $actor, [$target], BattlePoseRole::ATTACK, function () use (&$hits) { $hits++; }, static fn() => null);
  $field->setCommandPlayback($playback);
  foreach (['source' => $actor, 'target' => $target] as $stage => $recipient) {
    foreach ([0, 1] as $frame) {
      $at = $playback->plan->phases[$stage]['start'] + $frame * 12;
      $playback->update(($at - $playback->session->currentFrame) / 120);
      $canvas = $presentation->frame($field);
      $stroke = array_find($canvas->images, fn($image) => str_starts_with($image->id, 'command-effect-'));
      $recipientImage = array_find($canvas->images, fn($image) => $image->id === 'combatant-' . spl_object_id($recipient));
      $mirror = !$enemyActs;
      $authoredFlip = $reduced || $frame === 1;
      $slot = $recipient === $hero ? $layout->partySlots[0] : $battle->troop->getGraphicalSlot($recipient);
      $groundX = $slot->x + ($recipient === $actor && !$reduced
        ? ($enemyActs ? 1 : -1) * $slot->width * .25 * $playback->getAdvanceFraction() : 0);
      $x = $ground ? $groundX : $recipientImage->destination->x + $recipientImage->destination->width / 2;
      $y = $ground ? $slot->y : $recipientImage->destination->y + $recipientImage->destination->height / 2;
      $imagePivotX = $ground ? ($stroke->flipX ? .8 : .2) : .5;
      $imagePivotY = $ground ? ($stroke->flipY ? .25 : .75) : .5;
      expect($stroke)->not->toBeNull()->and($stroke->flipX)->toBe($mirror !== $authoredFlip)
        ->and($stroke->destination->x)->toEqualWithDelta($x + ($mirror ? -1 : 1) * $layout->uiGrid->cellWidth
          - $stroke->destination->width * $imagePivotX, .000001)
        ->and($stroke->destination->y)->toEqualWithDelta($y - $stroke->destination->height * $imagePivotY, .000001)
        ->and($hits)->toBe(0)->and($playback->presentationFailure)->toBeNull();
    }
  }
  $playback->update(10);
  expect($hits)->toBe(1)->and($playback->isCompleted)->toBeTrue()
    ->and(array_filter($presentation->frame($field)->images,
      fn($image) => str_starts_with($image->id, 'command-effect-')))->toBeEmpty();
})->with([false, true])->with([false, true])->with([.1, .9])->with([false, true])->with([false, true]);

it('keeps a statically registered creature the same size in differently sized formation slots', function () {
  [$battle, $catalog, , $enemies] = graphicalBattleFixture();
  $catalog = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, $catalog->enemies,
    ui: $catalog->ui, defaultArena: $catalog->defaultArena,
    enemyPoses: ['Twin' => new BattlePoseSet([], displayWidth: 90)]);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  foreach ($enemies as $enemy) {
    $slot = $battle->troop->getGraphicalSlot($enemy);
    $image = array_find($presentation->frame()->images,
      fn($image) => $image->id === 'combatant-' . spl_object_id($enemy));
    expect($image->destination->width)->toBe(90.0)
      ->and($image->destination->height)->toEqualWithDelta(181 * 90 / 143, .000001)
      ->and($image->destination->x + 45)->toEqualWithDelta($slot->x, .000001)
      ->and($image->destination->y + $image->destination->height)->toEqualWithDelta($slot->y, .000001)
      ->and($enemy->stats->currentHp)->toBe(100);
  }
});

it('selects each supplied command pose in both real battle engines and returns to the persistent state', function (bool $atb, bool $reduced, string $role) {
  $previous = getcwd();
  $root = sys_get_temp_dir() . '/ichiloto-all-command-poses-' . bin2hex(random_bytes(5));
  $summonDirectory = $root . '/assets/Cutscenes/Summons/test';
  mkdir($summonDirectory, 0777, true);
  file_put_contents($summonDirectory . '/test.data.php', "<?php return ['id' => 'test', 'name' => 'Caller', 'linkedActionId' => 'Authored Call'];");
  file_put_contents($summonDirectory . '/test.timeline.php', "<?php return ['fps' => 8, 'lengthFrames' => 2, 'tracks' => [], 'cues' => []];");
  $engine = null;
  try {
    chdir($root);
    [$engine, $context, $scene, $presentation, , $turn] = graphicalCommandRuntime($atb, $reduced, poses: graphicalCompletePoseSet());
    $battler = $turn->battler;
    $battler->stats->currentMp = $battler->stats->totalMp = 10;
    $battler->addStatStage('attack', 1);
    $turn->action = match ($role) {
      'attack' => new AttackAction('Renamed strike'),
      'skill' => new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Technique', '', '', 3, 0)),
      'magic' => new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(new \Ichiloto\Engine\Entities\Skills\MagicSkill('Spell', '', '', 3, 0)),
      'summon' => new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Authored Call', '', '', 3, 0)),
      'guard' => new \Ichiloto\Engine\Battle\Actions\GuardAction('Brace'),
      'item' => new \Ichiloto\Engine\Battle\Actions\ItemBattleAction(new \Ichiloto\Engine\Entities\Inventory\Items\Item(
        'Tonic', '', '', 1, effects: [new \Ichiloto\Engine\Entities\Effects\HPRecoveryEffect('Restore', '', 5, 1,
          \Ichiloto\Engine\Entities\Enumerations\ValueBasis::ACTUAL)])),
    };
    if ($role === 'guard' || $role === 'item') { $turn->targets = [$battler]; }
    $field = $scene->ui->fieldWindow;
    $identity = 'combatant-' . spl_object_id($battler);
    $baseline = array_column($presentation->frame($field)->images, null, 'id')[$identity];
    $engine->run($context);
    $playback = $field->getCommandPlayback();
    new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null,
      ($playback->plan->phases['announce']['start'] + 1) / 120);
    $engine->run($context);
    $image = array_column($presentation->frame($field)->images, null, 'id')[$identity];
    $expected = graphicalCompletePoseSet()->roles[$role]->getArtwork($this->root, 0, $reduced);
    expect($playback->getPoseRole($battler)->value)->toBe($role)
      ->and($image->asset)->toBe($expected->asset)->and($image->sourceRect)->toEqual($expected->sourceRect)
      ->and($image->destination->width)->toBe($baseline->destination->width)
      ->and($battler->stats->currentMp)->toBe(10);
    new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, 10);
    $engine->run($context);
    $rest = array_column($presentation->frame($field)->images, null, 'id')[$identity];
    $restRole = $role === 'guard' ? BattlePoseRole::GUARD : BattlePoseRole::ENHANCED;
    expect($field->getCommandPlayback())->toBeNull()
      ->and($rest->sourceRect)->toEqual(graphicalCompletePoseSet()->getPose($restRole)->getArtwork($this->root, 0)->sourceRect)
      ->and($rest->destination)->toEqual($baseline->destination)
      ->and($battler->stats->currentMp)->toBe(in_array($role, ['skill', 'magic', 'summon'], true) ? 7 : 10);
    $mp = $battler->stats->currentMp;
    $presentation->frame($field);
    expect($battler->stats->currentMp)->toBe($mp);
  } finally {
    $engine?->stop();
    chdir($previous);
    unlink($summonDirectory . '/test.data.php');
    unlink($summonDirectory . '/test.timeline.php');
    rmdir($summonDirectory);
    rmdir(dirname($summonDirectory));
    rmdir(dirname(dirname($summonDirectory)));
    rmdir($root . '/assets');
    rmdir($root);
  }
})->with([false, true])->with([false, true])->with(['attack', 'skill', 'magic', 'item', 'guard', 'summon']);

it('uses every persistent supplied pose and recovers immediately after cure revival and state cleanup', function (bool $reduced) {
  ConfigStore::put(PlaySettings::class, new PlaySettings(['reducedMotion' => $reduced]));
  [$battle, $catalog, $hero] = graphicalBattleFixture();
  $poses = graphicalCompletePoseSet();
  $catalog = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, $catalog->enemies, ui: $catalog->ui,
    defaultArena: $catalog->defaultArena, actorPoses: ['Hero' => $poses]);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $field = graphicalBattleScene($battle, $presentation)->ui->fieldWindow;
  $assertRole = function (BattlePoseRole $role) use ($hero, $poses, $presentation, $field, $reduced): void {
    $image = array_find($presentation->frame($field)->images, fn($image) => $image->id === 'combatant-' . spl_object_id($hero));
    $art = $poses->getPose($role)->getArtwork($this->root, 0, $reduced);
    expect($image->sourceRect)->toEqual($art->sourceRect)->and($image->opacity)->toBe(1.0)
      ->and($image->destination->y + $art->pivotY * ($image->destination->width / $art->width))->toBe(265.0);
  };
  $assertRole(BattlePoseRole::IDLE);
  $hero->addState(new \Ichiloto\Engine\Entities\States\State('venom', 'Venom'));
  $assertRole(BattlePoseRole::AFFLICTED);
  $hero->stats->currentHp = 0;
  $assertRole(BattlePoseRole::KNOCKOUT);
  $hero->stats->currentHp = 50;
  $assertRole(BattlePoseRole::AFFLICTED);
  $hero->removeState('venom');
  $hero->addStatStage('defence', 1);
  $assertRole(BattlePoseRole::ENHANCED);
  $hero->beginGuarding();
  $assertRole(BattlePoseRole::GUARD);
  $hero->clearBattleStates();
  $hero->resetStatStages();
  $assertRole(BattlePoseRole::IDLE);
})->with([false, true]);

it('retains registered resting scale when reaction art is absent or a command image is unusable', function (string $role, bool $reduced) {
  ConfigStore::put(PlaySettings::class, new PlaySettings(['reducedMotion' => $reduced]));
  [$battle, $catalog, $hero] = graphicalBattleFixture();
  $roles = graphicalCompletePoseSet()->roles;
  unset($roles['damage'], $roles['heal'], $roles['knockout']);
  $roles['attack'] = new BattlerPose('missing-attack.png');
  $poses = new BattlePoseSet($roles, displayWidth: 192);
  $hero->addStatStage('attack', 1);
  if ($role === 'knockout') { $hero->stats->currentHp = 0; }
  $catalog = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, $catalog->enemies, ui: $catalog->ui,
    defaultArena: $catalog->defaultArena, actorPoses: ['Hero' => $poses]);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $field = graphicalBattleScene($battle, $presentation)->ui->fieldWindow;
  $resolved = 0;
  $playback = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback(
    new \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline(new \Ichiloto\Engine\Battle\BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1)),
    $hero, [$hero], BattlePoseRole::ATTACK, function () use (&$resolved) { $resolved++; }, static fn() => null);
  $playback->setReaction($hero, BattlePoseRole::from($role));
  $field->setCommandPlayback($playback);
  $playback->update(.11);
  $image = array_find($presentation->frame($field)->images, fn($image) => $image->id === 'combatant-' . spl_object_id($hero));
  $fallback = $poses->getPose($role === 'knockout' ? BattlePoseRole::IDLE : BattlePoseRole::ENHANCED)->getArtwork($this->root, 0);
  expect($playback->getPoseRole($hero)->value)->toBe($role)
    ->and($image->asset)->toBe($fallback->asset)->and($image->sourceRect)->toEqual($fallback->sourceRect)
    ->and($image->destination->width / $fallback->width)->toEqualWithDelta(192 / $catalog->actors['Hero']->width, .000001)
    ->and($image->opacity)->toBe($role === 'knockout' ? .4 : 1.0)
    ->and($resolved)->toBe(0);
  $playback->update(10);
  expect($resolved)->toBe(1);
})->with(['damage', 'heal', 'attack', 'knockout'])->with([false, true]);

it('keeps registered base scale and ground anchor when every optional resting image is unavailable', function (bool $knockout, bool $reduced) {
  ConfigStore::put(PlaySettings::class, new PlaySettings(['reducedMotion' => $reduced]));
  [$battle, $catalog, $hero] = graphicalBattleFixture();
  $poses = new BattlePoseSet([
    'idle' => new BattlerPose('missing-idle.png'),
    'knockout' => new BattlerPose('missing-knockout.png'),
  ], displayWidth: 192);
  $catalog = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, $catalog->enemies,
    ui: $catalog->ui, defaultArena: $catalog->defaultArena, actorPoses: ['Hero' => $poses]);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  if ($knockout) { $hero->stats->currentHp = 0; }
  $image = array_find($presentation->frame()->images, fn($image) => $image->id === 'combatant-' . spl_object_id($hero));
  $art = $catalog->actors['Hero'];
  $slot = $catalog->ui->partySlots[0];
  expect($image->asset)->toBe($art->asset)
    ->and($image->destination)->toEqual($slot->place($art, 192))
    ->and($image->destination->x + $image->destination->width * $art->pivotX / $art->width)->toBe($slot->x)
    ->and($image->destination->y + $image->destination->height * $art->pivotY / $art->height)->toBe($slot->y)
    ->and($image->opacity)->toBe($knockout ? .4 : 1.0)
    ->and($hero->stats->currentHp)->toBe($knockout ? 0 : 100);
})->with([false, true])->with([false, true]);

it('derives enhanced and afflicted recipient art from resolved status effects in both real engines', function (bool $atb, int $delta) {
  [$engine, $context, $scene, $presentation, , $turn] = graphicalCommandRuntime($atb, poses: graphicalCompletePoseSet());
  $turn->action = new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(new \Ichiloto\Engine\Entities\Skills\SpecialSkill(
    'Neutral label', '', '', 0, 0, effects: [new \Ichiloto\Engine\Entities\Effects\SkillEffects\ModifyStatStageSkillEffect('attack', $delta)]));
  $engine->run($context);
  $playback = $scene->ui->fieldWindow->getCommandPlayback();
  $impact = array_find($playback->plan->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame'];
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, ($impact + 1) / 120);
  $engine->run($context);
  $target = $turn->targets[0];
  $role = $delta > 0 ? BattlePoseRole::ENHANCED : BattlePoseRole::AFFLICTED;
  $image = array_find($presentation->frame($scene->ui->fieldWindow)->images,
    fn($image) => $image->id === 'combatant-' . spl_object_id($target));
  expect($target->getStatStage('attack'))->toBe($delta)
    ->and($playback->getPoseRole($target))->toBe($role)
    ->and($image->sourceRect)->toEqual(graphicalCompletePoseSet()->getPose($role)->getArtwork($this->root, 0)->sourceRect)
    ->and($target->stats->currentHp)->toBe(100);
  $engine->stop();
})->with([false, true])->with([-1, 1]);

it('plays commands through both real engines with poses advance impact reaction and return', function (bool $atb, bool $enemyActs, bool $reduced) {
  [$engine, $context, $scene, $presentation, $action, $turn, $turnContext] = graphicalCommandRuntime($atb, $reduced, $enemyActs);
  $field = $scene->ui->fieldWindow;
  $baseline = array_column($presentation->frame($field)->images, null, 'id');
  $identity = 'combatant-' . spl_object_id($turn->battler);
  $engine->run($context);
  $playback = $field->getCommandPlayback();
  expect($playback)->not->toBeNull()->and($action->executions)->toBe(0)->and($turn->isCompleted)->toBeFalse();
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, .12);
  $engine->run($context);
  $advanced = array_column($presentation->frame($field)->images, null, 'id')[$identity];
  expect($advanced->asset)->toBe('test-sprite.png')->and($advanced->sourceRect)->not->toBeNull();
  if ($reduced) { expect($advanced->destination)->toEqual($baseline[$identity]->destination); }
  elseif ($enemyActs) { expect($advanced->destination->x)->toBeGreaterThan($baseline[$identity]->destination->x); }
  else { expect($advanced->destination->x)->toBeLessThan($baseline[$identity]->destination->x); }
  $field->pauseTiming();
  $frame = $playback->session->currentFrame;
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, 10);
  $engine->run($context);
  expect($playback->session->currentFrame)->toBe($frame)->and($action->executions)->toBe(0);
  $field->resumeTiming();
  $impact = array_find($playback->plan->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame'];
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null,
    ($impact - $frame + 1) / \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline::FPS);
  $engine->run($context);
  expect($action->executions)->toBe(1)->and($turn->targets[0]->stats->currentHp)->toBe(93)
    ->and($turn->isCompleted)->toBeTrue()
    ->and($playback->getPoseRole($turn->targets[0]))->toBe(\Ichiloto\Engine\Battle\Presentation\BattlePoseRole::DAMAGE);
  $reaction = $presentation->frame($field);
  expect($presentation->frame($field)->toArray())->toBe($reaction->toArray())->and($action->executions)->toBe(1);
  expect(array_column($reaction->composites, 'id'))->when(!$reduced,
    fn($expect) => $expect->toContain('command-tints-layer-101'));
  expect(array_column($reaction->textLayers, 'id'))->not->toContain('command-reaction-' . spl_object_id($turn->targets[0]));
  if (!$reduced) {
    $tint = array_column($reaction->composites, null, 'id')['command-tints-layer-101'];
    $recipient = array_column($reaction->images, null, 'id')['combatant-' . spl_object_id($turn->targets[0])];
    $mask = $tint->operations[0]->data['masks'][0];
    $size = PngAssetPreflight::inspect($this->root, $recipient->asset);
    expect($tint->destination)->toEqual($recipient->destination)->and($mask['asset'])->toBe($recipient->asset)
      ->and($mask['source']['x'])->toBe((float)($recipient->sourceRect->x / $size['width']))
      ->and($reaction->getOverlayProtection())->toContain($tint->destination);
  } else { expect($reaction->composites)->toBeEmpty(); }
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, 10);
  $engine->run($context);
  $rest = array_column($presentation->frame($field)->images, null, 'id')[$identity];
  expect($field->getCommandPlayback())->toBeNull()->and($field->getFeedback())->toBeEmpty()
    ->and($presentation->frame($field)->composites)->toBeEmpty()
    ->and($field->getActingBattler())->toBeNull()->and($rest->destination)->toEqual($baseline[$identity]->destination)
    ->and($turnContext->getCurrentTurn())->toBeNull()->and($action->executions)->toBe(1);
  $engine->stop();
})->with([[false, false, false], [true, false, false], [true, true, false], [false, true, true]]);

it('abandons a real in-flight command on engine stop without spending its gameplay action', function (bool $atb) {
  [$engine, $context, $scene, , $action] = graphicalCommandRuntime($atb);
  $engine->run($context);
  $playback = $scene->ui->fieldWindow->getCommandPlayback();
  $engine->stop();
  $playback->update(10);
  expect($action->executions)->toBe(0)->and($scene->ui->fieldWindow->getCommandPlayback())->toBeNull()
    ->and($scene->ui->fieldWindow->getFeedback())->toBeEmpty();
})->with([false, true]);

it('retains current command poses and result feedback when the renderer cannot composite tints', function (bool $atb, bool $reduced) {
  [$engine, $context, $scene, $presentation, $action, $turn] = graphicalCommandRuntime($atb, $reduced,
    poses: graphicalCompletePoseSet());
  new ReflectionProperty(Ichiloto\Engine\Core\Game::class, 'audioManager')->setValue($scene->getGame(),
    new class extends \Ichiloto\Engine\Audio\AudioManager {
      public function __construct() {}
      public function playSystemSound(\Ichiloto\Engine\Audio\Enumerations\SystemSound $sound): void {}
    });
  $capabilities = ['graphical_canvas', 'sprite_source_rect', 'canvas_clip_opacity'];
  $transport = new FakeRendererTransport();
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => $capabilities]))];
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $this->root,
    requiredCapabilities: $capabilities), $transport);
  $scene->getGame()->useRendererRuntime($runtime);
  try {
    $runtime->start('Untinted command', 135, 36);
    expect(array_all($presentation->requiredCapabilities(), $runtime->supports(...)))->toBeTrue();
    new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, 0);
    $engine->run($context);
    $field = $scene->ui->fieldWindow;
    $playback = $field->getCommandPlayback();
    $impact = array_find($playback->plan->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame'];
    new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, ($impact + 1) / 120);
    $engine->run($context);
    $frame = $scene->getPresentationCanvas();
    $recipient = array_find($frame->images, fn($image) => $image->id === 'combatant-' . spl_object_id($turn->targets[0]));
    $crop = graphicalCompletePoseSet()->getPose(BattlePoseRole::DAMAGE)->getArtwork($this->root, 0, $reduced)->sourceRect;
    expect($frame->composites)->toBeEmpty()->and($recipient->sourceRect)->toEqual($crop)
      ->and($field->getFeedback())->not->toBeEmpty()->and($turn->targets[0]->stats->currentHp)->toBe(93)
      ->and($action->executions)->toBe(1)->and($runtime->present($scene))->toBeTrue();
    if ($reduced) { expect($playback->presentationFailure)->toBeNull(); }
    else { expect($playback->presentationFailure?->getMessage())->toContain('cannot composite battle tints'); }
    $failure = $playback->presentationFailure;
    expect($runtime->present($scene))->toBeFalse()->and($playback->presentationFailure)->toBe($failure)
      ->and($action->executions)->toBe(1);
    $frames = RetainedFrameState::replay($transport->sent);
    expect(end($frames)['canvas']['composites'] ?? [])->toBeEmpty()
      ->and(array_column(end($frames)['canvas']['images'], 'id'))->toContain($recipient->id);
    new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, 10);
    $engine->run($context);
    $runtime->present($scene);
    expect($field->getCommandPlayback())->toBeNull()->and($field->getFeedback())->toBeEmpty()
      ->and($action->executions)->toBe(1)->and($scene->getPresentationCanvas()->composites)->toBeEmpty();
  } finally { $engine->stop(); $runtime->shutdown(); }
})->with([false, true])->with([false, true]);

it('shows healing from actual combat changes rather than the selected command name', function () {
  [$engine, $context, $scene, , $action, $turn] = graphicalCommandRuntime(true);
  $action->hpChange = 5;
  $turn->targets[0]->stats->currentHp = 70;
  $engine->run($context);
  $playback = $scene->ui->fieldWindow->getCommandPlayback();
  $impact = array_find($playback->plan->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame'];
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, ($impact + 1) / 120);
  $engine->run($context);
  expect($playback->getPoseRole($turn->targets[0]))->toBe(\Ichiloto\Engine\Battle\Presentation\BattlePoseRole::HEAL)
    ->and($turn->targets[0]->stats->currentHp)->toBe(75)->and($action->executions)->toBe(1);
  $engine->stop();
});

it('presents actual multi-hit multi-target results and misses without repeating costs or defeated hits', function (bool $atb, bool $miss) {
  [$engine, $context, $scene, $presentation, , $turn] = graphicalCommandRuntime($atb);
  $turn->targets = $scene->troop->members->toArray();
  $turn->battler->stats->currentMp = $turn->battler->stats->totalMp = 10;
  foreach ($turn->targets as $target) { $target->stats->defence = 0; $target->stats->evasion = $miss ? 100 : 0; }
  $turn->targets[0]->stats->currentHp = 15;
  $turn->action = new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(new \Ichiloto\Engine\Entities\Skills\SpecialSkill(
    'Three strikes', '', '*', 4, 0,
    scope: new \Ichiloto\Engine\Entities\ItemScope(number: \Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber::ALL),
    invocation: new \Ichiloto\Engine\Entities\Skills\SkillInvocation(accuracy: $miss ? 1 : 0, repeat: 3),
    effects: [new \Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect('10', variance: 0)]),
    random: new class implements CombatRandomSource {
      public function nextInt(int $minimum, int $maximum): int { return $maximum; }
    });
  $engine->run($context);
  $field = $scene->ui->fieldWindow;
  $playback = $field->getCommandPlayback();
  $impact = array_find($playback->plan->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame'];
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, ($impact + 1) / 120);
  $engine->run($context);
  $result = $turn->action->lastResult;
  expect($turn->battler->stats->currentMp)->toBe(6)->and($result->targetCount())->toBe(2)
    ->and($result->hitCount())->toBe($miss ? 0 : 5)
    ->and($result->actualHpLost())->toBe($miss ? 0 : 45)
    ->and(array_map(fn($target) => $target->stats->currentHp, $turn->targets))->toBe($miss ? [15, 100] : [0, 70])
    ->and($playback->getPoseRole($turn->targets[0]))->toBe($miss
      ? \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::IDLE : \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::KNOCKOUT)
    ->and($playback->getPoseRole($turn->targets[1]))->toBe($miss
      ? \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::IDLE : \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::DAMAGE)
    ->and(array_column($field->getFeedback(), 'battler'))->toBe($turn->targets);
  $first = $presentation->frame($field)->toArray();
  expect($presentation->frame($field)->toArray())->toBe($first)->and($turn->action->lastResult)->toBe($result);
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, 10);
  $engine->run($context);
  expect($field->getCommandPlayback())->toBeNull()->and($field->getFeedback())->toBeEmpty()
    ->and($turn->battler->stats->currentMp)->toBe(6)->and($turn->action->lastResult)->toBe($result);
  $engine->stop();
})->with([[false, false], [true, false], [false, true], [true, true]]);

it('retains a self-targeted guard pose without showing a false miss', function (bool $atb) {
  [$engine, $context, $scene, , , $turn] = graphicalCommandRuntime($atb);
  $turn->targets = [$turn->battler];
  $turn->action = new \Ichiloto\Engine\Battle\Actions\GuardAction('Guard');
  $engine->run($context);
  $field = $scene->ui->fieldWindow;
  $playback = $field->getCommandPlayback();
  $impact = array_find($playback->plan->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame'];
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, ($impact + 1) / 120);
  $engine->run($context);
  expect($turn->battler->isGuarding)->toBeTrue()->and($field->getFeedback())->toBeEmpty()
    ->and($playback->getPoseRole($turn->battler))->toBe(\Ichiloto\Engine\Battle\Presentation\BattlePoseRole::GUARD);
  $engine->stop();
})->with([false, true]);

it('uses registered base scale for optional geometry and diagnoses unusable registration without losing artwork', function (float $width) {
  [$battle, $catalog, $hero] = graphicalBattleFixture();
  $set = new \Ichiloto\Engine\Battle\Presentation\BattlePoseSet([
    'idle' => new \Ichiloto\Engine\Battle\Presentation\BattlerPose('graphical-canvas/synthetic-320x180.png', pivotY: .9),
  ], displayWidth: $width);
  $posed = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, $catalog->enemies, ui: $catalog->ui, defaultArena: $catalog->defaultArena, actorPoses: ['Hero' => $set]);
  $presentation = GraphicalBattlePresentation::prepare($battle, $posed, $this->root);
  $image = array_find($presentation->frame()->images, fn($image) => $image->id === 'combatant-' . spl_object_id($hero));
  $scale = $width === 120.0 ? 120 / 143 : 1;
  expect($image->asset)->toBe('graphical-canvas/synthetic-320x180.png')
    ->and($image->destination->width / 320)->toEqualWithDelta($scale, .000001)
    ->and($image->destination->height / 180)->toEqualWithDelta($scale, .000001)
    ->and($image->destination->x + 160 * $scale)->toEqualWithDelta(969, .000001)
    ->and($image->destination->y + 162 * $scale)->toEqualWithDelta(265, .000001);
  if ($width === 16000.0) {
    expect(file_get_contents($this->logRoot . '/warning.log'))->toContain('Registered base battler placement unavailable');
  }
})->with([120.0, 16000.0]);

it('diagnoses combined optional effect budgets while retaining the base frame and exactly one impact', function (bool $pixelBudget) {
  $root = createTestDirectory('ichiloto-battle-budget-');
  mkdir($root . '/graphical-canvas', 0777, true);
  foreach (['synthetic-143x181.png', 'synthetic-320x180.png'] as $file) {
    copy($this->root . '/graphical-canvas/' . $file, $root . '/graphical-canvas/' . $file);
  }
  try {
    if ($pixelBudget) { \Tests\Support\Rendering\writeTestPng($root . '/oversized.png', 4096, 4096); }
    [$battle, $catalog, $hero, $enemies] = graphicalBattleFixture();
    $catalog = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, $catalog->enemies,
      ui: $catalog->ui, defaultArena: $catalog->defaultArena,
      actorPoses: ['Hero' => new BattlePoseSet([], displayWidth: 192)]);
    $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $root);
    $scene = graphicalBattleScene($battle, $presentation);
    $scene->ui = new BattleScreen($scene);
    $effect = new \Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline('budget', '', fps: 10,
      playbackSegments: [['startFrame' => 0, 'endFrame' => 1, 'layer' => $pixelBudget ? 'image' : 'glyph',
        'drawCommands' => [['trackId' => 'spark', 'assetId' => $pixelBudget ? 'oversized.png' : '', 'content' => '*',
          'payload' => ['columns' => 1, 'rows' => 1, 'sourceFrame' => 0]]]]], defaults: ['lengthFrames' => 2]);
    $executions = 0;
    $playback = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback(
      new \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline(
        new \Ichiloto\Engine\Battle\BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
      $hero, [$enemies[0]], \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::ATTACK,
      function () use (&$executions) { $executions++; }, static fn() => null);
    $scene->ui->fieldWindow->setCommandPlayback($playback);
    $playback->update($playback->plan->phases['target']['start'] / 120);
    $ui = [];
    if (!$pixelBudget) {
      for ($index = 0; $index < \Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame::MAX_TEXT_LAYERS; $index++) {
        $ui[] = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer('ui-' . $index, 250, 0, 0,
          new \Ichiloto\Engine\Rendering\Transport\RendererGridConfig(1, 1),
          [new \Ichiloto\Engine\Rendering\Presentation\PresentationTextRun(0, 0, '*')]);
      }
    }
    $frame = $presentation->frame($scene->ui->fieldWindow, $ui);
    $base = array_find($frame->images, fn($image) => $image->id === 'combatant-' . spl_object_id($hero));
    $slot = $catalog->ui->partySlots[0];
    $registered = $slot->place($catalog->actors['Hero'], 192);
    $expected = new CanvasRectangle($registered->x - $slot->width * .25 * $playback->getAdvanceFraction(),
      $registered->y, $registered->width, $registered->height);
    expect($frame->images)->toHaveCount(4)->and($frame->textLayers)->toHaveCount(count($ui))
      ->and($playback->presentationFailure)->not->toBeNull()->and($executions)->toBe(0)
      ->and($base->asset)->toBe($catalog->actors['Hero']->asset)
      ->and($base->destination)->toEqual($expected);
    CanvasImagePreflight::inspect($frame->images, $root);
    $playback->update(10);
    expect($executions)->toBe(1)->and($playback->isCompleted)->toBeTrue();
    $playback->update(10);
    expect($executions)->toBe(1);
    $invalidUi = array_fill(0, 65, $ui[0] ?? new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer(
      'invalid-ui', 250, 0, 0, new \Ichiloto\Engine\Rendering\Transport\RendererGridConfig(1, 1), []));
    expect(fn() => $presentation->frame($scene->ui->fieldWindow, $invalidUi))->toThrow(InvalidArgumentException::class);
  } finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
  }
})->with([false, true]);

it('continues an actual command after a presentation redraw fails', function () {
  [$engine, $context, $scene, , $action] = graphicalCommandRuntime(false);
  $engine->run($context);
  $playback = $scene->ui->fieldWindow->getCommandPlayback();
  $scene->ui->failRedraw = true;
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, .05);
  $engine->run($context);
  expect($playback->presentationFailure?->getMessage())->toBe('effect redraw offline')
    ->and($action->executions)->toBe(0)->and($scene->ui->fieldWindow->getCommandPlayback())->toBe($playback);
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, 10);
  $engine->run($context);
  expect($action->executions)->toBe(1)->and($scene->ui->fieldWindow->getCommandPlayback())->toBeNull();
  $engine->stop();
});

it('cancels an in-flight command when its battle scene is stopped', function (bool $atb) {
  [$engine, $context, $scene, , $action] = graphicalCommandRuntime($atb);
  $events = $this->getMockBuilder(\Ichiloto\Engine\Events\EventManager::class)->disableOriginalConstructor()
    ->onlyMethods(['removeEventListener'])->getMock();
  new ReflectionProperty(AbstractScene::class, 'eventManager')->setValue($scene, $events);
  new ReflectionProperty(AbstractScene::class, 'modalEventHandler')->setValue($scene, static fn() => null);
  $engine->run($context);
  $playback = $scene->ui->fieldWindow->getCommandPlayback();
  $camera = $this->getMockBuilder(Camera::class)->disableOriginalConstructor()->onlyMethods(['stop'])->getMock();
  $camera->expects($this->exactly(2))->method('stop');
  new ReflectionProperty(AbstractScene::class, 'camera')->setValue($scene, $camera);
  $scene->stop();
  $scene->stop();
  $playback->update(10);
  expect($action->executions)->toBe(0)->and($playback->isCompleted)->toBeTrue()
    ->and($scene->ui->fieldWindow->getCommandPlayback())->toBeNull()->and($engine->state)->toBeNull();
})->with([false, true]);

it('does not cancel an engine now owned by a different battle screen', function () {
  [$engine, $context, $scene, , $action] = graphicalCommandRuntime(false);
  $engine->run($context);
  $engine->stopForScreen(new BattleScreen($scene));
  expect($scene->ui->fieldWindow->getCommandPlayback())->not->toBeNull()->and($action->executions)->toBe(0);
  $engine->stop();
});

it('removes terminal command shake while retaining anchored glyph effects and the shared playhead', function () {
  [, , $scene] = graphicalCommandRuntime(false);
  $field = $scene->ui->fieldWindow;
  $effect = new \Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene('shake', '', fps: 10,
    playbackSegments: [['startFrame' => 0, 'endFrame' => 3, 'layer' => 'glyph',
      'drawCommands' => [['trackId' => 'body', 'content' => '*', 'position' => ['x' => 10, 'y' => 2]]]]],
    defaults: ['lengthFrames' => 4]);
  $playback = new \Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback(
    new \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline(new \Ichiloto\Engine\Battle\BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
    $scene->party->battlers->toArray()[0], [$scene->troop->members->toArray()[0]],
    \Ichiloto\Engine\Battle\Presentation\BattlePoseRole::MAGIC, static fn() => null, static fn() => null);
  $field->setCommandPlayback($playback);
  $start = $playback->plan->phases['target']['start'];
  $playback->update($start / 120);
  $render = new ReflectionMethod($field, 'renderCommandEffects');
  $queued = new ReflectionProperty($field, 'magicCastEffects');
  $render->invoke($field);
  $baseX = $queued->getValue($field)[0]['x'];
  $field->beginSummonShake($start, 12, 2);
  $render->invoke($field);
  expect($queued->getValue($field)[0]['x'])->toBe($baseX);
  $playback->update(1 / 120);
  $render->invoke($field);
  expect($queued->getValue($field)[0]['x'])->toBe($baseX);
  ConfigStore::put(Ichiloto\Engine\Util\Config\ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => true]]));
  $render->invoke($field);
  expect($queued->getValue($field)[0]['x'])->toBe($baseX);
  $scene->getGame()->engine->stop();
});

it('keeps command visual cues owned by presenters instead of applying legacy fullscreen flashes and shake', function (bool $atb) {
  [$engine, $context, $scene, , $action, , $turnContext] = graphicalCommandRuntime($atb);
  new ReflectionProperty(BattleScene::class, 'graphicalPresentation')->setValue($scene, null);
  $engine->run($context);
  $field = $scene->ui->fieldWindow;
  $handle = new ReflectionMethod($engine->actionExecutionState, 'handleSummonCue');
  foreach (['flash' => ['scope' => 'screen', 'color' => 'white', 'durationFrames' => 20],
    'shake' => ['amplitude' => 5, 'durationFrames' => 20]] as $type => $payload) {
    $handle->invoke($engine->actionExecutionState, $turnContext, $scene->troop->members[0],
      ['type' => $type, 'payload' => $payload], 0, false);
  }
  expect(new ReflectionProperty($field, 'battleFlash')->getValue($field))->toBeNull()
    ->and(new ReflectionProperty($field, 'summonShake')->getValue($field))->toBeNull()
    ->and($action->executions)->toBe(0);
  $engine->stop();
})->with([false, true]);

it('loads numeric animation source and target effects through actual command execution', function (bool $broken) {
  $cwd = getcwd();
  $root = sys_get_temp_dir() . '/ichiloto-command-project-' . bin2hex(random_bytes(6));
  mkdir($root . '/assets/Data', 0777, true);
  mkdir($root . '/assets/Animations/spark', 0777, true);
  copy(__DIR__ . '/../Fixtures/Renderer/test-sprite.png', $root . '/assets/spark.png');
  $data = ['fps' => 5, 'lengthFrames' => 2, 'restFrame' => 1, 'tracks' => [[
    'id' => 'spark', 'type' => 'image', 'asset' => $broken ? 'missing.png' : 'spark.png',
    'sheet' => ['columns' => 2, 'rows' => 1],
    'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]],
  ]], 'cues' => [['id' => 'impact', 'frame' => 1, 'type' => 'applyEffect']],
    'effectTiming' => ['mode' => 'cue', 'cueId' => 'impact']];
  file_put_contents($root . '/assets/Animations/spark/spark.timeline.php', '<?php return ' . var_export($data, true) . ';');
  file_put_contents($root . '/assets/Data/animations.php', '<?php return ' . var_export([[
    'id' => 7, 'name' => 'A different animation name', 'sourceEffect' => 'spark', 'targetEffect' => 'spark',
    'frames' => [['index' => 1, 'cells' => [['symbol' => '*', 'x' => 0, 'y' => 0]]]],
  ]], true) . ';');
  try {
    chdir($root);
    \Ichiloto\Engine\Battle\BattleCommandCatalog::beginBattle();
    [$engine, $context, $scene, , , $turn, $turnContext] = graphicalCommandRuntime(false);
    $turn->battler->stats->currentMp = $turn->battler->stats->totalMp = 10;
    $turn->targets[0]->stats->currentHp = 70;
    $turn->action = new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(new \Ichiloto\Engine\Entities\Skills\SpecialSkill(
      'Changed display name', 'Heal', '*', 2, 0,
      effects: [new \Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect('5')], animationId: 7),
      random: new class implements CombatRandomSource {
        public function nextInt(int $minimum, int $maximum): int { return intdiv($minimum + $maximum, 2); }
      });
    $engine->run($context);
    $playback = $scene->ui->fieldWindow->getCommandPlayback();
    expect($turnContext->getEffectTimelineLibrary())->toBe($turnContext->getEffectTimelineLibrary());
    if (!$broken) {
      $terminalLength = (int)ceil($scene->ui->getPacing()->getTurnTimings($turn->action)->effectAnimation
        * \Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline::FPS);
      expect($playback->plan->phases['source']['length'])->toBe(48)
        ->and($playback->plan->phases['target']['length'])->toBe(max(48, $terminalLength))
        ->and($playback->plan->terminalTiming?->durationSeconds)->toBe($terminalLength / 120)
        ->and($playback->plan->terminalTarget?->sourceId)->toBe('legacy-7')
        ->and(array_find($playback->plan->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame'])
        ->toBe($playback->plan->phases['target']['start'] + 24);
    } else {
      expect(array_column($playback->plan->timeline->playbackSegments, 'layer'))->toBe(['glyph']);
    }
    $impact = array_find($playback->plan->timeline->cueSchedule, fn($cue) => $cue['type'] === 'commandImpact')['frame'];
    new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, ($impact + 1) / 120);
    $engine->run($context);
    expect($turn->battler->stats->currentMp)->toBe(8)->and($turn->targets[0]->stats->currentHp)->toBe(75)
      ->and($turn->isCompleted)->toBeTrue();
    new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, 10);
    $engine->run($context);
    expect($turn->battler->stats->currentMp)->toBe(8)->and($turn->targets[0]->stats->currentHp)->toBe(75);
    $engine->stop();
  } finally {
    chdir($cwd);
    \Ichiloto\Engine\Battle\BattleCommandCatalog::endBattle();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
  }
})->with([false, true]);

it('loads migrated paced terminal treatments without cells through either battle engine and presentation', function (bool $atb, bool $graphical, bool $reduced) {
  $cwd = getcwd();
  $root = sys_get_temp_dir() . '/ichiloto-paced-command-' . bin2hex(random_bytes(6));
  mkdir($root . '/assets/Data', 0777, true);
  mkdir($root . '/assets/Animations/paced', 0777, true);
  copy(__DIR__ . '/../Fixtures/Renderer/test-sprite.png', $root . '/assets/spark.png');
  $data = ['presentations' => [
    'terminal' => ['lengthFrames' => 5, 'restFrame' => 2, 'cadence' => 'battle_phase', 'tracks' => [[
      'id' => 'terminal', 'type' => 'glyph', 'anchor' => 'target',
      'keyframes' => array_map(static fn(int $frame): array => ['frame' => $frame, 'content' => '*',
        'position' => ['x' => $frame - 2, 'y' => 0], 'color' => 'yellow'], range(0, 4)),
    ]]],
    'graphical' => ['fps' => 25, 'lengthFrames' => 2, 'restFrame' => 1, 'tracks' => [[
      'id' => 'art', 'type' => 'image', 'asset' => 'spark.png', 'keyframes' => [['frame' => 0, 'duration' => 2]],
    ]]],
  ]];
  file_put_contents($root . '/assets/Animations/paced/paced.timeline.php', '<?php return ' . var_export($data, true) . ';');
  file_put_contents($root . '/assets/Data/animations.php', '<?php return ' . var_export([[
    'id' => 7, 'name' => 'Binding without cells', 'targetEffect' => 'paced',
  ]], true) . ';');
  $engine = null;
  try {
    chdir($root);
    \Ichiloto\Engine\Battle\BattleCommandCatalog::beginBattle();
    [$engine, $context, $scene, , , $turn] = graphicalCommandRuntime($atb, $reduced);
    if (!$graphical) { new ReflectionProperty(BattleScene::class, 'graphicalPresentation')->setValue($scene, null); }
    $turn->battler->stats->currentMp = $turn->battler->stats->totalMp = 10;
    $turn->targets[0]->stats->currentHp = 70;
    $turn->action = new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(new \Ichiloto\Engine\Entities\Skills\SpecialSkill(
      'Renamable command', 'Heal', '*', 2, 0,
      effects: [new \Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect('5')], animationId: 7),
      random: new class implements CombatRandomSource {
        public function nextInt(int $minimum, int $maximum): int { return intdiv($minimum + $maximum, 2); }
      });
    $engine->run($context);
    $playback = $scene->ui->fieldWindow->getCommandPlayback();
    $phaseLength = (int)ceil(max(.01, $scene->ui->getPacing()->getTurnTimings($turn->action)->effectAnimation) * 120 - 1e-9);
    expect($playback->plan->phases['target']['length'])->toBe($phaseLength)
      ->and($playback->plan->terminalTarget?->sourceId)->toBe($graphical ? 'paced' : null)
      ->and($playback->presentationFailure)->toBeNull();
    $targetStart = $playback->plan->phases['target']['start'];
    $playback->update(($targetStart + (int)ceil($phaseLength * 2 / 5)) / 120);
    $glyphs = $playback->getActiveSegments($reduced, true);
    expect(array_column($glyphs, 'layer'))->toContain('glyph')
      ->and($glyphs[0]['drawCommands'][0]['payload']['anchor'])->toBe('target');
    new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, 10);
    $engine->run($context);
    expect($turn->battler->stats->currentMp)->toBe(8)->and($turn->targets[0]->stats->currentHp)->toBe(75)
      ->and($scene->ui->fieldWindow->getCommandPlayback())->toBeNull();
    $engine->run($context);
    expect($turn->battler->stats->currentMp)->toBe(8)->and($turn->targets[0]->stats->currentHp)->toBe(75);
  } finally {
    $engine?->stop();
    chdir($cwd);
    \Ichiloto\Engine\Battle\BattleCommandCatalog::endBattle();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
  }
})->with([false, true])->with([false, true])->with([false, true]);

it('plays two explicitly authored slash passes after caster art with one real command resolution', function (bool $atb, bool $reduced) {
  $previous = getcwd();
  $root = sys_get_temp_dir() . '/ichiloto-dual-effect-' . bin2hex(random_bytes(6));
  mkdir($root . '/assets/Data', 0777, true);
  mkdir($root . '/assets/graphical-canvas', 0777, true);
  foreach (['synthetic-143x181.png', 'synthetic-320x180.png'] as $file) {
    copy($this->root . '/graphical-canvas/' . $file, $root . '/assets/graphical-canvas/' . $file);
  }
  foreach (['test-sprite.png', 'cast.png', 'slash.png'] as $file) {
    copy($this->root . '/test-sprite.png', $root . '/assets/' . $file);
  }
  foreach (['cast' => 8, 'two-slashes' => 16] as $id => $length) {
    mkdir($root . '/assets/Animations/' . $id, 0777, true);
    $effect = ['fps' => 12, 'lengthFrames' => $length, 'restFrame' => 3, 'tracks' => [[
      'id' => $id, 'type' => 'image', 'asset' => $id === 'cast' ? 'cast.png' : 'slash.png',
      'sheet' => ['columns' => 8, 'rows' => 1], 'cells' => ['width' => 4, 'height' => 4],
      'keyframes' => array_map(static fn(int $frame): array => ['frame' => $frame, 'sourceFrame' => $frame % 8], range(0, $length - 1)),
    ]]];
    if ($id === 'two-slashes') {
      $effect['cues'] = [['id' => 'impact', 'frame' => 15, 'type' => 'applyEffect']];
      $effect['effectTiming'] = ['mode' => 'cue', 'cueId' => 'impact'];
    }
    file_put_contents($root . '/assets/Animations/' . $id . '/' . $id . '.timeline.php',
      '<?php return ' . var_export($effect, true) . ';');
  }
  file_put_contents($root . '/assets/Data/animations.php', '<?php return ' . var_export([[
    'id' => 7, 'name' => 'Authored two passes', 'sourceEffect' => 'cast', 'targetEffect' => 'two-slashes',
    'maxFrames' => 16, 'frames' => [
      ['index' => 1, 'cells' => [['symbol' => '/', 'x' => 0, 'y' => 0]]],
      ['index' => 9, 'cells' => [['symbol' => '\\', 'x' => 0, 'y' => 0]]],
    ],
  ]], true) . ';');
  $engine = null;
  try {
    chdir($root);
    \Ichiloto\Engine\Battle\BattleCommandCatalog::beginBattle();
    $poses = graphicalCompletePoseSet();
    [$engine, $context, $scene, , , $turn] = graphicalCommandRuntime($atb, $reduced, poses: $poses);
    new ReflectionProperty(Ichiloto\Engine\Core\Game::class, 'audioManager')->setValue($scene->getGame(),
      new class extends \Ichiloto\Engine\Audio\AudioManager {
        public function __construct() {}
        public function playSystemSound(\Ichiloto\Engine\Audio\Enumerations\SystemSound $sound): void {}
      });
    [, $catalog] = graphicalBattleFixture();
    $battle = new ReflectionProperty(BattleScene::class, 'config')->getValue($scene);
    $catalog = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, $catalog->enemies,
      ui: $catalog->ui, defaultArena: $catalog->defaultArena, actorPoses: ['Hero' => $poses], enemyPoses: ['Twin' => $poses]);
    $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $root . '/assets');
    new ReflectionProperty(BattleScene::class, 'graphicalPresentation')->setValue($scene, $presentation);
    $turn->battler->stats->currentMp = $turn->battler->stats->totalMp = 10;
    $turn->targets[0]->stats->defence = 0;
    $turn->action = new \Ichiloto\Engine\Battle\Actions\SkillBattleAction(new \Ichiloto\Engine\Entities\Skills\SpecialSkill(
      'Renamed command', '', '*', 4, 0,
      invocation: new \Ichiloto\Engine\Entities\Skills\SkillInvocation(accuracy: 0, repeat: 2),
      effects: [new \Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect('10', variance: 0)], animationId: 7),
      random: new class implements CombatRandomSource {
        public function nextInt(int $minimum, int $maximum): int { return $maximum; }
      });
    $engine->run($context);
    $field = $scene->ui->fieldWindow;
    $playback = $field->getCommandPlayback();
    $observed = [];
    foreach (['source' => 8, 'target' => 16] as $phase => $length) {
      foreach (range(0, $length - 1) as $frameIndex) {
        $wanted = $playback->plan->phases[$phase]['start'] + $frameIndex * 10;
        new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null,
          ($wanted - $playback->session->currentFrame) / 120);
        $engine->run($context);
        $canvas = $presentation->frame($field);
        $effects = array_values(array_filter($canvas->images,
          static fn(CanvasImage $image): bool => str_starts_with($image->id, 'command-effect-')));
        $subject = $phase === 'source' ? $turn->battler : $turn->targets[0];
        expect($effects)->toHaveCount(1)->and($effects[0]->id)->toEndWith('-' . spl_object_id($subject))
          ->and($effects[0]->asset)->toBe($phase === 'source' ? 'cast.png' : 'slash.png')
          ->and($effects[0]->sourceRect->x)->toBe(($reduced ? 3 : $frameIndex % 8) * 4)
          ->and($effects[0]->destination->width)->toBe(192.0)
          ->and($playback->presentationFailure)->toBeNull();
        if ($phase === 'target') { $observed[] = $effects[0]->sourceRect->x; }
        expect($turn->battler->stats->currentMp)->toBe($phase === 'target' && $frameIndex === 15 ? 6 : 10)
          ->and($turn->targets[0]->stats->currentHp)->toBe($phase === 'target' && $frameIndex === 15 ? 80 : 100);
      }
    }
    $result = $turn->action->lastResult;
    expect($observed)->toBe($reduced ? array_fill(0, 16, 12) : [...range(0, 28, 4), ...range(0, 28, 4)])
      ->and($result->hitCount())->toBe(2)->and($result->actualHpLost())->toBe(20)
      ->and($playback->getPoseRole($turn->targets[0]))->toBe(BattlePoseRole::DAMAGE);
    $presentation->frame($field);
    new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, 10);
    $engine->run($context);
    expect($field->getCommandPlayback())->toBeNull()->and($turn->action->lastResult)->toBe($result)
      ->and($turn->battler->stats->currentMp)->toBe(6)->and($turn->targets[0]->stats->currentHp)->toBe(80);
  } finally {
    $engine?->stop();
    chdir($previous);
    \Ichiloto\Engine\Battle\BattleCommandCatalog::endBattle();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
  }
})->with([false, true])->with([false, true]);

it('keeps terminal effect and popup anchors on the presented battler position', function (bool $enemyActs, bool $reduced) {
  [$engine, $context, $scene, , , $turn] = graphicalCommandRuntime(false, $reduced, $enemyActs);
  $field = $scene->ui->fieldWindow;
  $origin = new ReflectionMethod($field, 'resolveActionAnimationOrigin');
  $baseline = $origin->invoke($field, $turn->battler, \Ichiloto\Engine\Animations\AnimationTargetPosition::CENTER);
  $engine->run($context);
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, .2);
  $engine->run($context);
  $active = $origin->invoke($field, $turn->battler, \Ichiloto\Engine\Animations\AnimationTargetPosition::CENTER);
  expect($active['y'])->toBe($baseline['y']);
  if ($reduced) { expect($active)->toBe($baseline); }
  elseif ($enemyActs) { expect($active['x'])->toBeGreaterThan($baseline['x']); }
  else { expect($active['x'])->toBeLessThan($baseline['x']); }
  $engine->stop();
})->with([[false, false], [true, false], [false, true]]);

it('holds one terminal step through source target and result stages then returns without changing outcome timing', function (bool $atb, bool $enemyActs, bool $reduced) {
  [$engine, $context, $scene, , $action, $turn] = graphicalCommandRuntime($atb, $reduced, $enemyActs);
  new ReflectionProperty(BattleScene::class, 'graphicalPresentation')->setValue($scene, null);
  $field = $scene->ui->fieldWindow;
  $origin = new ReflectionMethod($field, 'resolveActionAnimationOrigin');
  $getOrigin = fn() => $origin->invoke($field, $turn->battler, \Ichiloto\Engine\Animations\AnimationTargetPosition::CENTER);
  $idle = $getOrigin();
  $engine->run($context);
  $playback = $field->getCommandPlayback();
  $advanced = $getOrigin();
  if ($reduced) { expect($advanced)->toBe($idle); }
  elseif ($enemyActs) { expect($advanced['x'])->toBeGreaterThan($idle['x']); }
  else { expect($advanced['x'])->toBeLessThan($idle['x']); }
  foreach (['announce', 'source', 'target', 'reaction', 'return', 'finish'] as $phase) {
    $frame = $playback->plan->phases[$phase]['start'];
    new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null,
      ($frame - $playback->session->currentFrame + .1) / 120);
    $engine->run($context);
    expect($playback->phase)->toBe($phase)->and($getOrigin())->toBe(in_array($phase, ['return', 'finish'], true) ? $idle : $advanced);
    expect($action->executions)->toBe(in_array($phase, ['reaction', 'return', 'finish'], true) ? 1 : 0);
    if ($phase === 'announce') {
      expect($scene->ui->messageWindow->presentationSnapshot())->toContain($turn->battler->name, 'Fixture');
    } elseif ($phase === 'reaction') {
      expect(array_column($field->getFeedback(), 'battler'))->toBe($turn->targets)
        ->and($turn->targets[0]->stats->currentHp)->toBe(93);
    }
  }
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, 10);
  $engine->run($context);
  expect($field->getCommandPlayback())->toBeNull()->and($field->getFeedback())->toBeEmpty()
    ->and($getOrigin())->toBe($idle)->and($action->executions)->toBe(1);
  $engine->stop();
})->with([[false, false, false], [true, false, false], [false, true, false], [true, true, true]]);

it('emits no terminal bytes while an actual command holds its announcement across updates', function (bool $atb) {
  [$engine, $context, $scene, , $action] = graphicalCommandRuntime($atb);
  new ReflectionProperty(BattleScene::class, 'graphicalPresentation')->setValue($scene, null);
  $engine->run($context);
  $playback = $scene->ui->fieldWindow->getCommandPlayback();
  new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null,
    ($playback->plan->phases['announce']['start'] + 1) / 120);
  $engine->run($context);
  $before = Console::snapshot();
  Console::setTerminalOutputEnabled(true);
  new ReflectionProperty(Console::class, 'terminalOutputStream')->setValue(null, null);
  ob_start();
  try {
    new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime')->setValue(null, 1 / 120);
    $engine->run($context);
    $scene->ui->refreshField();
    expect(ob_get_contents())->toBe('')->and(Console::snapshot())->toEqual($before)
      ->and($action->executions)->toBe(0)->and($playback->phase)->toBe('announce');
  } finally {
    ob_end_clean();
    Console::setTerminalOutputEnabled(false);
    $engine->stop();
  }
})->with([false, true]);

afterEach(function () {
  foreach (glob($this->logRoot . '/*') ?: [] as $file) { unlink($file); }
  if (is_dir($this->logRoot)) { rmdir($this->logRoot); }
  foreach ($this->statics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('composes Results over the frozen real battlefield without changing awarded gameplay facts', function () {
  [$battle, $catalog, $hero] = graphicalBattleFixture(true);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $scene = graphicalBattleScene($battle, $presentation);
  new ReflectionProperty(BattleScene::class, 'resultsSkin')->setValue($scene, graphicalResultsFixtureSkin());
  $scene->result = new BattleResult('Victory', rewards: new BattleRewards(10000, 42,
    [ExperienceAwarder::award($hero, 10000)]));
  $awardedExperience = $hero->currentExp;
  $scene->beginResults();
  $scene->resultsPlayback->update(3);
  $frame = $scene->getPresentationCanvas();
  expect($scene->hasGraphicalResults())->toBeTrue()
    ->and($frame->images[0])->toEqual($presentation->frame()->images[0])
    ->and(array_column($frame->textLayers, 'id'))->toContain('results-heading')
    ->and(array_column($frame->textLayers, 'id'))->not->toContain('battle-ui-0', 'battle-pause');
  expect($scene->getPresentationCanvas()->toArray())->toBe($frame->toArray());
  $scene->endResults();
  expect($scene->hasGraphicalResults())->toBeFalse()
    ->and($scene->resultsPlayback)->toBeNull()
    ->and($hero->currentExp)->toBe($awardedExperience);
  $scene->beginResults();
  expect($scene->resultsPlayback->currentStage()['kind'])->toBe('primary')
    ->and($hero->currentExp)->toBe($awardedExperience);
});

it('keeps victory input presentation-only and finishes the exit without an extra confirmation', function () {
  [$battle, $catalog, $hero] = graphicalBattleFixture(true);
  $scene = graphicalBattleScene($battle, GraphicalBattlePresentation::prepare($battle, $catalog, $this->root));
  new ReflectionProperty(BattleScene::class, 'resultsSkin')->setValue($scene, graphicalResultsFixtureSkin());
  $scene->result = new BattleResult('Victory', rewards: new BattleRewards(1, 0, [ExperienceAwarder::award($hero, 1)]));
  $context = new Ichiloto\Engine\Scenes\SceneStateContext($scene);
  $victory = new class($context) extends Ichiloto\Engine\Scenes\Battle\States\BattleVictoryState {
    protected function playVictoryMusic(): void {}
  };
  $end = new class($context) extends Ichiloto\Engine\Scenes\Battle\States\BattleEndState {
    public bool $entered = false;
    public function enter(): void { $this->entered = true; }
  };
  new ReflectionProperty(BattleScene::class, 'endState')->setValue($scene, $end);
  $delta = new ReflectionProperty(Ichiloto\Engine\Core\Time::class, 'deltaTime');
  $oldDelta = $delta->getValue();
  new ReflectionProperty(InputManager::class, 'config')->setValue(null,
    ['action' => ['keys' => [Ichiloto\Engine\IO\Enumerations\KeyCode::ENTER]]]);
  try {
    $scene->setState($victory);
    $delta->setValue(null, 0.01);
    $victory->execute();
    new ReflectionProperty(InputManager::class, 'keyPress')->setValue(null, Ichiloto\Engine\IO\Enumerations\KeyCode::ENTER);
    new ReflectionProperty(InputManager::class, 'previousKeyPress')->setValue(null, null);
    $victory->execute();
    expect($scene->resultsPlayback->isComplete())->toBeTrue()->and($end->entered)->toBeFalse();
    $victory->execute();
    expect($end->entered)->toBeFalse();
    new ReflectionProperty(InputManager::class, 'keyPress')->setValue(null, null);
    $delta->setValue(null, 0.2);
    $victory->execute();
    new ReflectionProperty(InputManager::class, 'keyPress')->setValue(null, Ichiloto\Engine\IO\Enumerations\KeyCode::ENTER);
    $victory->execute();
    expect($scene->resultsPlayback->isExiting())->toBeTrue()->and($end->entered)->toBeFalse();
    new ReflectionProperty(InputManager::class, 'keyPress')->setValue(null, null);
    $delta->setValue(null, 30.0);
    $victory->resume();
    $victory->execute();
    expect($end->entered)->toBeFalse();
    $delta->setValue(null, 0.5);
    $victory->execute();
    expect($end->entered)->toBeTrue()->and($scene->resultsPlayback)->toBeNull()
      ->and($hero->currentExp)->toBe(2);
  } finally {
    $delta->setValue(null, $oldDelta);
  }
});

it('resolves pivots and contain sizes in independent fractional canvas units', function () {
  [$battle, $catalog] = graphicalBattleFixture();
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $canvas = $presentation->frame();
  expect($canvas->images[1]->destination->toArray())->toBe(['x' => 897.5, 'y' => 84.0, 'width' => 143.0, 'height' => 181.0]);
  Console::syncDimensions(80, 24);
  expect($presentation->frame()->toArray())->toBe($canvas->toArray());
});

it('selects an explicit scene or declared default independently of troop identity', function () {
  [$fixture, $catalog] = graphicalBattleFixture();
  $base = $catalog->arenas['arena.test'];
  $yard = new BattleArenaDefinition('Yard', new CanvasImage('yard', $base->background->asset, $base->background->destination));
  $catalog = new BattlePresentationCatalog(['arena.test' => $base, 'arena.yard' => $yard],
    $catalog->actors, $catalog->enemies, ui: $catalog->ui, defaultArena: 'arena.test');
  $battle = new BattleConfig($fixture->party, $fixture->troop, settings: ['battleArena' => 'arena.yard']);
  expect(GraphicalBattlePresentation::prepare($battle, $catalog, $this->root)->arena)->toBe($yard)
    ->and(GraphicalBattlePresentation::prepare($fixture, $catalog, $this->root)->arena)->toBe($base)
    ->and($catalog->getArenaChoices())->toBe(['arena.test' => 'Test scene', 'arena.yard' => 'Yard'])
    ->and($battle->entryRulesEvaluated())->toBeFalse();
  $restored = unserialize(serialize($battle));
  expect($catalog->getArenaFor($restored))->toBe($yard)
    ->and(GraphicalBattlePresentation::prepare($restored, $catalog, $this->root)->frame()->images)->toHaveCount(4);
  $renamed = new BattleConfig($fixture->party, new Troop('Different name', definitionId: 'different-id'));
  expect($catalog->getArenaFor($renamed))->toBe($base);
  $noDefault = new BattlePresentationCatalog(['Twins' => $base], [], []);
  expect($noDefault->getArenaFor($fixture))->toBeNull()
    ->and(fn() => new BattlePresentationCatalog([], [], [], defaultArena: 'missing'))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid explicit scene keys rather than using the default', function (mixed $key, string $error) {
  [$fixture, $catalog] = graphicalBattleFixture();
  $battle = new BattleConfig($fixture->party, $fixture->troop, settings: ['battleArena' => $key]);
  expect(fn() => GraphicalBattlePresentation::prepare($battle, $catalog, $this->root))->toThrow($error)
    ->and($battle->entryRulesEvaluated())->toBeFalse();
})->with([
  'unknown' => ['arena.missing', RuntimeException::class],
  'empty' => ['', InvalidArgumentException::class],
  'controls' => ["arena\nyard", InvalidArgumentException::class],
  'array' => [[], InvalidArgumentException::class],
  'integer' => [42, InvalidArgumentException::class],
  'null' => [null, InvalidArgumentException::class],
]);

it('keeps the same troop formation across every background and after roster reordering', function () {
  [$fixture, $catalog, , $enemies] = graphicalBattleFixture();
  $base = $catalog->arenas['arena.test'];
  $yard = new BattleArenaDefinition('Yard', new CanvasImage('yard', $base->background->asset, $base->background->destination));
  $catalog = new BattlePresentationCatalog(['arena.test' => $base, 'arena.yard' => $yard],
    $catalog->actors, $catalog->enemies, ui: $catalog->ui, defaultArena: 'arena.test');
  $before = GraphicalBattlePresentation::prepare($fixture, $catalog, $this->root)->frame();
  $battle = new BattleConfig($fixture->party, $fixture->troop, settings: ['battleArena' => 'arena.yard']);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  expect(array_slice($presentation->frame()->images, 1))->toEqual(array_slice($before->images, 1))
    ->and($presentation->layout)->toBe($catalog->ui);
  $battle->troop->members[0] = $enemies[1];
  $battle->troop->members[1] = $enemies[0];
  expect(array_slice($presentation->frame()->images, 1))->toEqual(array_slice($before->images, 1));
});

it('refuses missing or out-of-bounds troop placements without fabricating defaults', function (array $slots, string $error) {
  [$fixture, $catalog, , $enemies] = graphicalBattleFixture();
  $battle = new BattleConfig($fixture->party, new Troop('Twins', $enemies, graphicalFormation: $slots));
  expect(fn() => GraphicalBattlePresentation::prepare($battle, $catalog, $this->root))->toThrow($error)
    ->and($battle->entryRulesEvaluated())->toBeFalse();
})->with([
  'none' => [[], RuntimeException::class],
  'missing member' => [[new BattlerSlot(350, 400, 100, 100), null], RuntimeException::class],
  'out of canvas' => [[new BattlerSlot(1400, 400, 100, 100), new BattlerSlot(530, 470, 120, 100)], InvalidArgumentException::class],
]);

it('degrades an explicit arena without a catalog to the terminal presentation and never blocks combat', function (bool $native) {
  [$fixture] = graphicalBattleFixture();
  $battle = new BattleConfig($fixture->party, $fixture->troop, settings: ['battleArena' => 'arena.yard']);
  $runtime = $native ? new RendererRuntime(
    new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $this->root), new FakeRendererTransport()) : null;
  $scene = graphicalBattleConfigurationScene($runtime);
  // Presentation must never change whether combat happens: a missing
  // catalog under an explicit arena logs and degrades, and terminal play
  // never loads the catalog at all.
  $scene->configure($battle);
  expect($scene->config)->toBe($battle)->and($battle->entryRulesEvaluated())->toBeTrue()
    ->and($scene->graphicalPresentation)->toBeNull()
    ->and($scene->battleUiLayout)->toBeNull();
})->with([true, false]);

it('keeps repeated enemy instance IDs through target reorder feedback and removal', function () {
  [$battle, $catalog, $hero, $enemies] = graphicalBattleFixture();

  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $scene = graphicalBattleScene($battle, $presentation);
  $field = $scene->ui->fieldWindow;
  $field->focusTroopBattlers([1, 0]);
  $field->stepPartyBattlerForward($hero, 0);
  $first = $presentation->frame($field);
  $ids = array_map(fn($image) => $image->id, $first->images);
  expect(array_unique($ids))->toHaveCount(4)
    ->and(array_column($first->indicators, 'id'))->toBe([
      'selected-' . spl_object_id($enemies[0]), 'selected-' . spl_object_id($enemies[1]),
    ]);
  $field->focusTroopBattlers([0, 1]);
  expect($presentation->frame($field)->toArray())->toBe($first->toArray());
  $enemies[0]->stats->currentHp = 0;
  $field->showStatChangePopup($enemies[0], [['text' => '100', 'color' => Color::LIGHT_RED], ['text' => 'KO']]);
  $during = $presentation->frame($field);
  expect($during->images)->toHaveCount(4)
    ->and(array_any($during->textLayers, fn($layer) => array_any($layer->runs, fn($run) => $run->text === '100')))->toBeTrue();
  $field->clearStatChangePopups();
  $field->stepPartyBattlerBack($hero, 0);
  $after = $presentation->frame($field);
  expect(array_map(fn($image) => $image->id, $after->images))->toBe([$ids[0], $ids[1], $ids[3]])
    ->and($after->images[2]->destination)->toEqual($first->images[3]->destination)
    ->and($after->indicators)->toHaveCount(1);
});

it('selects the graphical field before constructing terminal battler output and clips UI to owned windows', function () {
  [$battle, $catalog] = graphicalBattleFixture();
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $scene = graphicalBattleScene($battle, $presentation);
  Console::clear();
  $scene->ui->renderField();
  expect(trim(implode('', Console::snapshot()->rows)))->toBe('');
  Console::write('NOT A UI WINDOW', 50, 15);
  $scene->ui->showControls();
  $scene->ui->showMessage('Target: Twin');
  $layers = BattleCanvasUiAdapter::collect($scene, $presentation->layout);
  $text = implode('', array_map(fn($layer) => implode('', array_map(fn($run) => $run->text, $layer->runs)), $layers));
  expect($text)->toContain('Target: Twin')->not->toContain('NOT A UI WINDOW')->not->toContain('ASCII MUST NOT DRAW');
  expect(array_all($layers, fn($layer) => array_all($layer->runs, fn($run) => $run->background !== null)))->toBeTrue();
  $scene->ui->hideMessage();
  $scene->ui->hideControls();
  expect(BattleCanvasUiAdapter::collect($scene, $presentation->layout))->toBe([]);
});

it('keeps terminal battlefield output when optional graphical metadata is absent', function () {
  [$battle] = graphicalBattleFixture();
  expect(BattlePresentationCatalog::load($this->root))->toBeNull()
    ->and(GraphicalBattlePresentation::prepare($battle, new BattlePresentationCatalog([], [], []), '/missing'))->toBeNull();
  $scene = graphicalBattleScene($battle, null);
  $scene->ui->renderField();
  expect(implode('', Console::snapshot()->rows))->toContain('ASCII MUST NOT DRAW')
    ->and($scene->getPresentationCanvas())->toBeNull();
});

it('uses shared native controls without requiring encounter artwork and keeps the field below them', function () {
  [$battle, $catalog] = graphicalBattleFixture(skinned: true);
  $arena = $catalog->arenas['arena.test'];
  $layout = $catalog->ui;
  $catalog = new BattlePresentationCatalog([], [], [], ui: $layout);
  expect(GraphicalBattlePresentation::prepare($battle, $catalog, '/no-artwork-required'))->toBeNull()
    ->and($catalog->requiredCapabilities())->toBe(['graphical_canvas', 'sprite_source_rect', 'canvas_clip_opacity', 'canvas_glyph_effects']);
  $scene = graphicalBattleScene($battle, null, $layout);
  Console::clear();
  $scene->ui->renderField();
  $scene->ui->showControls();
  $scene->ui->showMessage('Shared action banner');
  Console::write('OLD FOOTER MUST NOT DRAW', 2, 32);
  Console::withLayer('modal', fn() => Console::write('MODAL ABOVE HUD', 3, 33), 2000);
  $frame = $scene->getPresentationCanvas();
  $layers = array_column($frame->textLayers, null, 'id');
  $field = $layers['battle-field'];
  expect($scene->ui->usesGraphicalField())->toBeFalse()
    ->and(implode('', array_column($field->runs, 'text')))->toContain('ASCII MUST NOT DRAW')->not->toContain('OLD FOOTER')
    ->and($field->layer)->toBe(0)
    ->and(array_all($frame->images, fn($image) => $image->layer > $field->layer))->toBeTrue()
    ->and(array_any($frame->images, fn($image) => str_starts_with($image->id, 'hud-stats-')))->toBeTrue()
    ->and(array_any($frame->textLayers, fn($layer) => $layer->layer >= 1500
      && str_contains(implode('', array_column($layer->runs, 'text')), 'MODAL ABOVE HUD')))->toBeTrue()
    ->and(implode('', array_map(fn($layer) => implode('', array_column($layer->runs, 'text')), $frame->textLayers)))
      ->not->toContain('OLD FOOTER MUST NOT DRAW')->toContain('Shared action banner');
  $scene->ui->hideMessage();
  $scene->ui->hideControls();
  expect($scene->getPresentationCanvas()->images)->toBe([]);
});

it('shares geometry while allowing a scene skin without moving formations', function () {
  [$battle, $plain] = graphicalBattleFixture();
  [, $skinned] = graphicalBattleFixture(skinned: true);
  $layout = $skinned->ui;
  $inherited = new BattlePresentationCatalog($plain->arenas, $plain->actors, $plain->enemies,
    ui: $layout, defaultArena: $plain->defaultArena);
  $presentation = GraphicalBattlePresentation::prepare($battle, $inherited, $this->root);
  expect($presentation->layout)->toBe($layout)
    ->and($presentation->frame()->images[0])->toBe($plain->arenas['arena.test']->background);
  $scene = new BattleArenaDefinition('Distinct skin', $presentation->arena->background, $layout->skin);
  expect($plain->ui->getForArena($scene)->partySlots)->toBe($plain->ui->partySlots)
    ->and($layout->getForArena($scene))->toBe($layout)
    ->and(new BattlePresentationCatalog([], [], [], ui: new BattleCanvasLayout(1350, 720)))->toBeInstanceOf(BattlePresentationCatalog::class);
});

it('uses live skin controls without readmitting terminal cells and keeps markers independently owned', function () {
  [$battle, $catalog, $hero, $enemies] = graphicalBattleFixture(skinned: true);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  expect($presentation->requiredCapabilities())->toBe(['graphical_canvas', 'sprite_source_rect', 'canvas_clip_opacity', 'canvas_glyph_effects']);
  $scene = graphicalBattleScene($battle, $presentation);
  $scene->ui->showControls();
  $scene->ui->showMessage("First\nSecond");
  $field = $scene->ui->fieldWindow;
  $field->focusTroopBattlers([1, 0]);
  $field->setTroopTargetQueue([0 => 1]);
  $field->stepPartyBattlerForward($hero, 0);
  $ui = BattleCanvasUiAdapter::collect($scene, $presentation->layout);
  expect($ui)->toBe([]);
  $hud = BattleHudSnapshot::fromScreen($scene->ui);
  expect($hud->messageRows)->toBe(2);
  $frame = $presentation->frame($field, $ui, $hud, 'target', 0);
  $images = array_column($frame->images, null, 'id');
  expect($images)->toHaveKeys(['field-cursor-1-1', 'queued-' . spl_object_id($enemies[0]) . '-1-1'])
    ->and(array_filter($frame->images, static fn($image) => str_starts_with($image->id, 'acting-')))->toBe([])
    ->and($frame->indicators)->toBe([]);
  $second = $presentation->frame($field, $ui, $hud, 'target', 0.6);
  expect(array_column($second->images, null, 'id')['field-cursor-1-1']->destination->x)
    ->toBe($images['field-cursor-1-1']->destination->x + 4);
  $field->clearTroopFocus();
  $field->clearTargetIndicators();
  $field->stepPartyBattlerBack($hero, 0);
  $scene->ui->hideMessage();
  $cleared = $presentation->frame($field, [], BattleHudSnapshot::fromScreen($scene->ui), null, 0.6);
  expect(array_filter($cleared->images, fn($image) => preg_match('/^(field-cursor|queued-|selected-|acting-|hud-message)/', $image->id)))->toBe([]);
});

it('keeps directional cursors attached to focused and queued targets without adding text layers', function ($reducedMotion) {
  ConfigStore::put(Ichiloto\Engine\Util\Config\ProjectConfig::class,
    new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
  [$battle, $catalog, $hero, $enemies] = graphicalBattleFixture(skinned: true, directionalCursor: true);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $scene = graphicalBattleScene($battle, $presentation);
  $field = $scene->ui->fieldWindow;
  $field->focusTroopBattlers([1, 0]);
  $field->setTroopTargetQueue([0 => 1]);
  $field->stepPartyBattlerForward($hero, 0);
  $first = $presentation->frame($field, focus: 'target', now: 0);
  $second = $presentation->frame($field, focus: 'target', now: 0.6);
  $images = array_column($first->images, null, 'id');
  $later = array_column($second->images, null, 'id');
  expect(array_filter($first->images, fn($image) => preg_match('/^(selected-|field-cursor)/', $image->id)))->toBe([])
    ->and($images)->toHaveKey('queued-' . spl_object_id($enemies[0]) . '-1-1')
    ->and(array_filter($first->images, static fn($image) => str_starts_with($image->id, 'acting-')))->toBe([])
    ->and($first->textLayers)->toHaveCount(2);
  foreach ($enemies as $enemy) {
    $id = spl_object_id($enemy);
    $cursor = $images['target-cursor-' . $id . '-1-1']->destination;
    $battler = $images['combatant-' . $id]->destination;
    expect($cursor->x + $cursor->width / 2)->toBe($battler->x + $battler->width / 2)
      ->and($cursor->y + $cursor->height)->toBe($battler->y - 6)
      ->and($later['target-cursor-' . $id . '-1-1']->destination->y)->toBe($cursor->y - ($reducedMotion ? 0 : 4));
  }
  $field->focusTroopBattlers([0, 1]);
  expect($presentation->frame($field, focus: 'target', now: 0)->toArray())->toBe($first->toArray());
  $field->clearTroopFocus();
  $queued = $presentation->frame($field, focus: 'target', now: 0.6);
  $queuedImages = array_column($queued->images, null, 'id');
  expect(array_filter($queued->images, fn($image) => str_starts_with($image->id, 'target-cursor-')))->toHaveCount(1)
    ->and($queuedImages['target-cursor-' . spl_object_id($enemies[0]) . '-1-1']->destination)
    ->toEqual($images['target-cursor-' . spl_object_id($enemies[0]) . '-1-1']->destination);
  $field->clearTargetIndicators();
  $field->stepPartyBattlerBack($hero, 0);
  expect(array_filter($presentation->frame($field)->images,
    fn($image) => preg_match('/^(target-cursor-|queued-|acting-)/', $image->id)))->toBe([]);
})->with([false, true]);

it('retains graphical battlers and named targetable fallbacks when another artwork binding is absent', function () {
  [$battle, $catalog] = graphicalBattleFixture();
  $missing = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, [], ui: $catalog->ui, defaultArena: $catalog->defaultArena);
  $frame = GraphicalBattlePresentation::prepare($battle, $missing, $this->root)->frame();
  expect($frame->images)->toHaveCount(2)
    ->and(implode(' ', array_merge(...array_map(fn($layer) => array_column($layer->runs, 'text'), $frame->textLayers))))
    ->toContain($battle->troop->members->toArray()[0]->name);
  expect($battle->entryRulesEvaluated())->toBeFalse()
    ->and($battle->troop->members->toArray()[0]->stats->currentHp)->toBe(100);
});

it('moves a party target cursor beside the actor when the action banner covers its head', function () {
  [$battle, $catalog] = graphicalBattleFixture(skinned: true, directionalCursor: true);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $scene = graphicalBattleScene($battle, $presentation);
  $scene->ui->fieldWindow->focusPartyBattler(0);
  $scene->ui->showMessage('Cure');
  $frame = $presentation->frame($scene->ui->fieldWindow, [], BattleHudSnapshot::fromScreen($scene->ui), 'target', 0);
  $cursor = array_values(array_filter($frame->images, fn($image) => str_starts_with($image->id, 'target-cursor-')))[0];
  $hero = $frame->images[1]->destination;
  expect($cursor->destination->x + $cursor->destination->width)->toBe($hero->x - 6)
    ->and($cursor->destination->y)->toBeGreaterThanOrEqual(80.0);
});

it('preflights dimensions paths and crops without a PNG decoder', function () {
  expect(PngAssetPreflight::inspect($this->root, 'test-sprite.png'))->toBe(['width' => 32, 'height' => 48]);
  expect(fn() => PngAssetPreflight::inspect($this->root, '../outside.png'))->toThrow(InvalidArgumentException::class);
  expect(fn() => PngAssetPreflight::inspect($this->root, 'absent.png'))->toThrow(RuntimeException::class);
  expect(fn() => PngAssetPreflight::inspect($this->root, 'test-sprite.png', new SpriteSourceRect(31, 47, 2, 2)))
    ->toThrow(RuntimeException::class, 'bounds');
});

it('preserves gameplay and random draws while generating and replaying graphical frames', function () {
  $outcomes = [];
  foreach ([false, true] as $graphical) {
    [$battle, $catalog, $hero, $enemies] = graphicalBattleFixture();
    $presentation = $graphical ? GraphicalBattlePresentation::prepare($battle, $catalog, $this->root) : null;
    $random = new class implements CombatRandomSource {
      public array $draws = [];
      private SeededCombatRandomSource $source;
      public function __construct() { $this->source = new SeededCombatRandomSource(42); }
      public function nextInt(int $minimum, int $maximum): int {
        $value = $this->source->nextInt($minimum, $maximum);
        $this->draws[] = [$minimum, $maximum, $value];
        return $value;
      }
    };
    $action = new AttackAction('Attack', new CombatResolver(), $random);
    $hits = [];
    foreach ([$enemies[1], $enemies[0], $enemies[1], $enemies[0]] as $target) {
      $presentation?->frame();
      $action->execute($hero, [$target]);
      $hits[] = $action->lastResult->actualHpLost();
      $presentation?->frame();
      $presentation?->frame();
    }
    $outcomes[] = [$hits, $random->draws, $hero->stats->currentHp, $hero->stats->currentMp,
      array_map(fn($enemy) => $enemy->stats->currentHp, $enemies)];
  }
  expect($outcomes[0])->toBe($outcomes[1]);
});

it('removes implicit reserve fallback from rendering and only changes roster at an opted-in resolution', function (bool $replace) {
  [$battle, $catalog] = graphicalBattleFixture();
  if ($replace) {
    $battle = new BattleConfig($battle->party, $battle->troop, settings: ['reservePolicy' => 'replace_after_wipeout']);
  }
  foreach (['Second', 'Third', 'Reserve'] as $name) { $battle->party->addMember(new Character($name, 1, new Stats(currentHp: 100, totalHp: 100))); }
  $actors = $catalog->actors;
  foreach (['Second', 'Third', 'Reserve'] as $name) { $actors[$name] = $actors['Hero']; }
  $presentation = GraphicalBattlePresentation::prepare($battle, new BattlePresentationCatalog($catalog->arenas, $actors, $catalog->enemies, ui: $catalog->ui, defaultArena: $catalog->defaultArena), $this->root);
  expect($presentation->frame()->images)->toHaveCount(6);
  $members = $battle->party->members->toArray();
  foreach (array_slice($members, 0, 3) as $member) { $member->stats->currentHp = 0; }
  $frame = $presentation->frame();
  expect($frame->images)->toHaveCount(6)
    ->and(array_column(array_slice($frame->images, 1, 3), 'id'))->toBe(array_map(
      static fn($member): string => 'combatant-' . spl_object_id($member), array_slice($members, 0, 3)))
    ->and($battle->partyRoster->promoteReservesAfterWipeout())->toBe($replace);
  $frame = $presentation->frame();
  expect($frame->images)->toHaveCount($replace ? 4 : 6);
  if ($replace) { expect($frame->images[1]->id)->toBe('combatant-' . spl_object_id($members[3])); }
})->with(['default defeat' => false, 'explicit replacement' => true]);

it('replaces a real battle canvas with the normal scene frame through the shared runtime', function () {
  [$battle, $catalog] = graphicalBattleFixture();
  $scene = graphicalBattleScene($battle, GraphicalBattlePresentation::prepare($battle, $catalog, $this->root));
  $transport = new FakeRendererTransport();
  $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["graphical_canvas"]}')];
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $this->root,
    requiredCapabilities: [RendererSessionConfig::GRAPHICAL_CANVAS]), $transport);
  try {
    $runtime->start('Canvas battle', 135, 36);
    $runtime->present($scene);
    $frames = RetainedFrameState::replay($transport->sent);
    expect(end($frames)['canvas']['images'])->toHaveCount(4)
      ->and(end($frames)['textLayers'])->toBe([]);
    expect($runtime->present($scene))->toBeFalse();
    Console::write('FIELD', 1, 1);
    $runtime->present(null);
    $frames = RetainedFrameState::replay($transport->sent);
    expect(end($frames))->not->toHaveKey('canvas');
    expect(implode('', array_column(end($frames)['textLayers'][0]['runs'], 'text')))->toContain('FIELD');
    expect(array_filter(end($transport->sent)->payload['operations'],
      fn($operation) => $operation['op'] === 'remove' && $operation['kind'] === 'canvas'))->not->toBeEmpty();
  } finally { $runtime->shutdown(); }
});

it('loads current optional metadata without persisting it in battle state', function () {
  $root = sys_get_temp_dir() . '/ichiloto-battle-catalog-' . bin2hex(random_bytes(5));
  $file = $root . '/' . BattlePresentationCatalog::FILE;
  mkdir(dirname($file), 0777, true);
  try {
    expect(BattlePresentationCatalog::load($root))->toBeNull();
    copy(__DIR__ . '/../Fixtures/BattlePresentation/catalog.php', $file);
    $first = BattlePresentationCatalog::load($root);
    $second = BattlePresentationCatalog::load($root);
    expect($first)->toBeInstanceOf(BattlePresentationCatalog::class)->not->toBe($second)
      ->and($first->actors['Hero']->pivotX)->toBe(71.5);
    [$battle] = graphicalBattleFixture();
    expect($battle->__serialize())->not->toHaveKey('graphicalPresentation')->not->toHaveKey('canvas');
    $frame = GraphicalBattlePresentation::prepare($battle, $first, $root)->frame();
    expect($frame->images)->toBeEmpty()
      ->and($frame->textLayers)->not->toBeEmpty()
      ->and($battle->entryRulesEvaluated())->toBeFalse()
      ->and(file_get_contents($this->logRoot . '/warning.log'))->toContain('readable PNG');
  } finally { unlink($file); rmdir(dirname($file)); rmdir($root . '/Data'); rmdir($root); }
});

it('rejects malformed typed artwork and independent placement geometry', function (Closure $invalid) {
  expect($invalid)->toThrow(InvalidArgumentException::class);
})->with([
  'nonfinite pivot' => fn() => new BattlerArtwork('test.png', 32, 48, NAN, 48),
  'pivot outside crop' => fn() => new BattlerArtwork('test.png', 32, 48, 33, 48),
  'mismatched crop' => fn() => new BattlerArtwork('test.png', 32, 48, 16, 48, new SpriteSourceRect(0, 0, 31, 48)),
  'nonfinite slot' => fn() => new BattlerSlot(INF, 0, 32, 48),
  'empty slot' => fn() => new BattlerSlot(0, 0, 0, 48),
  'untyped catalog' => fn() => new BattlePresentationCatalog(['encounter' => []], [], []),
]);

it('keeps battle configuration playable when graphical capabilities are unavailable', function () {
  $root = sys_get_temp_dir() . '/ichiloto-battle-startup-' . bin2hex(random_bytes(5));
  $file = $root . '/' . BattlePresentationCatalog::FILE;
  mkdir(dirname($file), 0777, true);
  copy(__DIR__ . '/../Fixtures/BattlePresentation/catalog.php', $file);
  foreach (['hero', 'twin'] as $name) { copy($this->root . '/graphical-canvas/synthetic-143x181.png', $root . '/' . $name . '.png'); }
  copy($this->root . '/graphical-canvas/synthetic-320x180.png', $root . '/arena.png');
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $root), new FakeRendererTransport());
  $scene = graphicalBattleConfigurationScene($runtime);
  try {
    [$battle] = graphicalBattleFixture();
    $scene->configure($battle);
    expect($battle->entryRulesEvaluated())->toBeTrue()
      ->and($scene->config)->toBe($battle)->and($scene->graphicalPresentation)->toBeNull()
      ->and($scene->battleUiLayout)->toBeNull()
      ->and(file_get_contents($this->logRoot . '/error.log'))->toContain('negotiated graphical_canvas');
  } finally {
    $runtime->shutdown();
    foreach (['hero.png', 'twin.png', 'arena.png', BattlePresentationCatalog::FILE] as $asset) { unlink($root . '/' . $asset); }
    rmdir(dirname($file)); rmdir($root . '/Data'); rmdir($root);
  }
});

it('configures shared UI for consecutive encounters while terminal ignores optional assets', function (bool $native, bool $compositing, bool $results) {
  $root = sys_get_temp_dir() . '/ichiloto-shared-ui-' . bin2hex(random_bytes(5));
  $file = $root . '/' . BattlePresentationCatalog::FILE;
  mkdir(dirname($file), 0777, true);
  copy(__DIR__ . '/../Fixtures/BattlePresentation/shared-ui.php', $file);
  if ($results) {
    $code = <<<'PHP'
<?php
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleResultsSkin;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
$catalog = require __FIXTURE__;
$portraits = [];
foreach (['Hero', 'Second', 'Third', 'Fourth'] as $id) {
  $portraits[$id] = ['bust' => new CanvasNineSlice($id . '.png', new SpriteSourceRect(0, 0, 2048, 2048))];
}
return new BattlePresentationCatalog([], [], [], ui: $catalog->ui, results: new BattleResultsSkin(
  array_fill_keys(['panel', 'quiet', 'track', 'selector', 'portrait', 'exp', 'divider', 'button'], $catalog->ui->skin->textures['panel']),
  array_fill_keys(['text', 'muted', 'accent', 'positive', 'negative', 'ink'], $catalog->ui->skin->colors['text']), $portraits));
PHP;
    file_put_contents($file, str_replace('__FIXTURE__',
      var_export(__DIR__ . '/../Fixtures/BattlePresentation/shared-ui.php', true), $code));
    if ($native) {
      // The catalog exceeds 64 MiB, but each event displays only one bust. Header checks only.
      foreach (['Hero', 'Second', 'Third', 'Fourth'] as $id) {
        file_put_contents($root . '/' . $id . '.png', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', 2048, 2048));
      }
    }
  }
  if ($native) { copy($this->root . '/test-sprite.png', $root . '/skin.png'); }
  $runtime = null;
  try {
    if ($native) {
      $transport = new FakeRendererTransport();
      $capabilities = ['graphical_canvas', 'sprite_source_rect', 'canvas_clip_opacity', 'canvas_glyph_effects'];
      if ($compositing) { $capabilities[] = 'canvas_compositing'; }
      $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => $capabilities]))];
      $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $root,
        requiredCapabilities: $capabilities), $transport);
      $runtime->start('Shared UI', 135, 36);
    }
    $scene = graphicalBattleConfigurationScene($runtime);
    foreach (['Twins', 'Another encounter'] as $name) {
      [$fixture] = graphicalBattleFixture();
      $battle = new BattleConfig($fixture->party, new Troop($name, $fixture->troop->members->toArray()));
      $scene->configure($battle);
      expect($scene->config)->toBe($battle)
        ->and($battle->entryRulesEvaluated())->toBeTrue()
        ->and($scene->graphicalPresentation)->toBeNull()
        ->and($scene->battleUiLayout !== null)->toBe($native)
        ->and($scene->resultsSkin !== null)->toBe($native && $results);
      $entryState = $scene->state;
      if ($native) {
        // This configuration fixture does not enter states; prepare the screen without starting combat.
        new ReflectionProperty(AbstractScene::class, 'camera')->setValue($scene, new Camera($scene, 135, 36));
        $scene->ui = new BattleScreen($scene);
        $scene->ui->render();
      }
      $canvas = $scene->getPresentationCanvas();
      expect($scene->state)->toBe($entryState);
      if ($native) {
        expect($canvas)->toBeInstanceOf(PresentationCanvas::class)
          ->and(array_column($canvas->images, 'asset'))->toContain('skin.png')
          ->and([$canvas->width, $canvas->height])->toBe([1350, 720]);
      } else { expect($canvas)->toBeNull(); }
    }
  } finally {
    $runtime?->shutdown();
    if ($native) { unlink($root . '/skin.png'); }
    if ($native && $results) {
      foreach (['Hero', 'Second', 'Third', 'Fourth'] as $id) { unlink($root . '/' . $id . '.png'); }
    }
    unlink($file); rmdir(dirname($file)); rmdir($root . '/Data'); rmdir($root);
  }
})->with([[true, true], [true, false], [false, false]])->with([true, false]);

it('logs unusable shared UI assets or capabilities and still starts combat', function (?string $missing) {
  $root = sys_get_temp_dir() . '/ichiloto-shared-ui-invalid-' . bin2hex(random_bytes(5));
  $file = $root . '/' . BattlePresentationCatalog::FILE;
  mkdir(dirname($file), 0777, true);
  copy(__DIR__ . '/../Fixtures/BattlePresentation/shared-ui.php', $file);
  if ($missing !== null) { copy($this->root . '/test-sprite.png', $root . '/skin.png'); }
  $capabilities = array_values(array_diff(['graphical_canvas', 'sprite_source_rect', 'canvas_clip_opacity', 'canvas_compositing', 'canvas_glyph_effects'], [$missing]));
  if ($missing === 'graphical_canvas') { $capabilities = ['sprite_source_rect']; }
  $transport = new FakeRendererTransport();
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => $capabilities]))];
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $root,
    requiredCapabilities: $capabilities), $transport);
  try {
    $runtime->start('Invalid shared UI', 135, 36);
    $scene = graphicalBattleConfigurationScene($runtime);
    [$battle] = graphicalBattleFixture();
    $scene->configure($battle);
    expect($scene->config)->toBe($battle)
      ->and($scene->graphicalPresentation)->toBeNull()->and($battle->entryRulesEvaluated())->toBeTrue();
    if ($missing === null) {
      expect($scene->battleUiLayout)->not->toBeNull()
        ->and(file_get_contents($this->logRoot . '/warning.log'))->toContain('skin.png')
        ->and(is_file($this->logRoot . '/error.log'))->toBeFalse();
    } else {
      expect($scene->battleUiLayout)->toBeNull()
        ->and(file_get_contents($this->logRoot . '/error.log'))->toContain('Graphical battle presentation degraded', 'negotiated ' . $missing);
    }
  } finally {
    $runtime->shutdown();
    if ($missing !== null) { unlink($root . '/skin.png'); }
    unlink($file); rmdir(dirname($file)); rmdir($root . '/Data'); rmdir($root);
  }
})->with([null, 'graphical_canvas', 'sprite_source_rect', 'canvas_clip_opacity', 'canvas_glyph_effects']);

it('requires crop capability and removes party KO captions without changing health or fallback art', function () {
  [$battle, $catalog, $hero] = graphicalBattleFixture();
  $art = new BattlerArtwork('graphical-canvas/synthetic-143x181.png', 143, 181, 71.5, 181, new SpriteSourceRect(0, 0, 143, 181));
  $catalog = new BattlePresentationCatalog($catalog->arenas, ['Hero' => $art], $catalog->enemies, ui: $catalog->ui, defaultArena: $catalog->defaultArena);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  expect($presentation->requiredCapabilities())->toBe(['graphical_canvas', 'sprite_source_rect', 'canvas_clip_opacity']);
  $scene = graphicalBattleScene($battle, $presentation);
  $hero->stats->currentHp = 0;
  $scene->ui->fieldWindow->focusPartyBattlers([0]);
  $frame = $presentation->frame($scene->ui->fieldWindow);
  expect($frame->images[1]->opacity)->toBe(0.4)
    ->and(array_any($frame->textLayers, fn($layer) => array_any($layer->runs, fn($run) => $run->text === 'KO')))->toBeFalse()
    ->and($frame->indicators)->toHaveCount(1)
    ->and($hero->stats->currentHp)->toBe(0);
});

it('shows registered knockout artwork without KO captions and restores idle artwork after revival', function (bool $reducedMotion, bool $skinned) {
  ConfigStore::put(Ichiloto\Engine\Util\Config\ProjectConfig::class,
    new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
  [$battle, $catalog, $hero] = graphicalBattleFixture($skinned);
  $pose = new \Ichiloto\Engine\Battle\Presentation\BattlerPose('graphical-canvas/synthetic-320x180.png', pivotY: .9);
  $poses = new \Ichiloto\Engine\Battle\Presentation\BattlePoseSet(['knockout' => $pose], displayWidth: 200);
  $catalog = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, $catalog->enemies,
    ui: $catalog->ui, defaultArena: $catalog->defaultArena, actorPoses: ['Hero' => $poses]);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $scene = graphicalBattleScene($battle, $presentation);
  $hero->stats->currentHp = 0;
  $knockout = $presentation->frame($scene->ui->fieldWindow);
  $image = $knockout->images[1];
  $expected = $catalog->ui->partySlots[0]->placeAtScale($pose->getArtwork($this->root, 0, $reducedMotion),
    200 / $catalog->actors['Hero']->width);
  expect($image->asset)->toBe($pose->asset)
    ->and($image->opacity)->toBe(1.0)
    ->and($image->destination->toArray())->toBe($expected->toArray())
    ->and(array_any($knockout->textLayers, fn($layer) => array_any($layer->runs, fn($run) => $run->text === 'KO')))->toBeFalse()
    ->and($hero->stats->currentHp)->toBe(0);

  $hero->stats->currentHp = 50;
  $revived = $presentation->frame($scene->ui->fieldWindow);
  expect($revived->images[1]->id)->toBe($image->id)
    ->and($revived->images[1]->asset)->toBe($catalog->actors['Hero']->asset)
    ->and($revived->images[1]->opacity)->toBe(1.0)
    ->and(array_any($revived->textLayers, fn($layer) => array_any($layer->runs, fn($run) => $run->text === 'KO')))->toBeFalse()
    ->and($hero->stats->currentHp)->toBe(50);
})->with([false, true])->with([false, true]);

it('removes KO popups for all graphical recipients and themes while retaining terminal feedback and damage',
  function (bool $skinned, bool $reducedMotion, bool $partyRecipient) {
    ConfigStore::put(Ichiloto\Engine\Util\Config\ProjectConfig::class,
      new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    [$battle, $catalog, $hero, $enemies] = graphicalBattleFixture($skinned);
    $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
    $field = graphicalBattleScene($battle, $presentation)->ui->fieldWindow;
    $recipient = $partyRecipient ? $hero : $enemies[0];
    $recipient->stats->currentHp = 0;
    $field->showStatChangePopup($recipient, [
      ['text' => '100', 'color' => Color::LIGHT_RED, 'role' => \Ichiloto\Engine\Battle\Presentation\BattleFeedbackRole::DAMAGE],
      ['text' => 'Fallen', 'color' => Color::YELLOW, 'role' => \Ichiloto\Engine\Battle\Presentation\BattleFeedbackRole::KO],
    ], durationSeconds: 2.0);
    $original = $field->getFeedback();
    $frame = $presentation->frame($field);
    $texts = array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), $frame->textLayers));
    expect($texts)->toContain('100')->not->toContain('Fallen', 'KO')
      ->and($field->getFeedback())->toBe($original)
      ->and(array_column($original[0]['lines'], 'text'))->toBe(['100', 'Fallen'])
      ->and($recipient->stats->currentHp)->toBe(0);
    $field->clearStatChangePopups();
    $cleared = $presentation->frame($field);
    expect(array_any($cleared->textLayers, static fn($layer) => array_any($layer->runs,
      static fn($run) => in_array($run->text, ['Fallen', 'KO'], true))))->toBeFalse();
  })->with([false, true])->with([false, true])->with([false, true]);

it('diagnoses an unregistered knockout pose once while preserving the readable base fallback', function () {
  [$battle, $catalog, $hero] = graphicalBattleFixture();
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $scene = graphicalBattleScene($battle, $presentation);
  $hero->stats->currentHp = 0;
  foreach (range(1, 3) as $_) {
    $frame = $presentation->frame($scene->ui->fieldWindow);
    expect($frame->images[1]->asset)->toBe($catalog->actors['Hero']->asset)
      ->and($frame->images[1]->opacity)->toBe(0.4)
      ->and($hero->stats->currentHp)->toBe(0);
  }
  expect(substr_count(file_get_contents($this->logRoot . '/warning.log'), 'No battle pose registered for Hero: knockout'))->toBe(1);
});

it('places selected ally damage and healing feedback together clear of a visible action banner', function ($value, $color) {
  [$battle, $catalog, $hero] = graphicalBattleFixture();
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $scene = graphicalBattleScene($battle, $presentation);
  $scene->ui->showMessage('An action is resolving');
  $scene->ui->showControls();
  $scene->ui->fieldWindow->focusPartyBattlers([0]);
  $scene->ui->fieldWindow->showStatChangePopup($hero, [['text' => $value, 'color' => $color], ['text' => 'RESULT']]);
  $ui = BattleCanvasUiAdapter::collect($scene, $presentation->layout);
  $frame = $presentation->frame($scene->ui->fieldWindow, $ui);
  $feedback = $frame->textLayers[0];
  expect(array_map(fn($run) => $run->text, $feedback->runs))->toBe(['Hero', $value, 'RESULT'])
    ->and($feedback->y)->toBeGreaterThanOrEqual(80)
    ->and($feedback->bounds->y + $feedback->bounds->height)->toBeLessThanOrEqual(600);
  foreach ($ui as $layer) {
    foreach ($layer->runs as $run) {
      $x = $layer->x + $run->column * $layer->grid->cellWidth;
      $y = $layer->y + $run->row * $layer->grid->cellHeight;
      $right = $x + mb_strlen($run->text, 'UTF-8') * $layer->grid->cellWidth;
      $bottom = $y + $layer->grid->cellHeight;
      expect($feedback->bounds->x >= $right || $feedback->bounds->x + $feedback->bounds->width <= $x
        || $feedback->bounds->y >= $bottom || $feedback->bounds->y + $feedback->bounds->height <= $y)->toBeTrue();
    }
  }
  $scene->ui->fieldWindow->clearStatChangePopups();
  expect(array_map(fn($run) => $run->text, $presentation->frame($scene->ui->fieldWindow, $ui)->textLayers[0]->runs))->toBe(['Hero']);
})->with([['50', Color::LIGHT_RED], ['+50', Color::LIGHT_GREEN]]);

it('reconciles authored battler metadata with the artwork on disk', function () {
  // Fits: the authored instance passes through untouched.
  $fits = new BattlerArtwork('a.png', 100, 120, 50, 120, new SpriteSourceRect(10, 10, 100, 120));
  expect($fits->clampedTo(200, 200))->toBe($fits);

  // The image shrank under the crop: crop and pivot clamp to what exists
  // now, because artwork changes throughout development and the image on
  // disk is this moment's truth.
  $clamped = $fits->clampedTo(60, 70);
  expect($clamped->sourceRect->toArray())->toBe(['x' => 10, 'y' => 10, 'width' => 50, 'height' => 60])
    ->and([$clamped->width, $clamped->height])->toBe([50, 60])
    ->and([$clamped->pivotX, $clamped->pivotY])->toBe([50.0, 60.0]);

  // The crop no longer exists at all: fall back to the whole image, so
  // the developer always sees their art.
  $fallback = $fits->clampedTo(10, 10);
  expect($fallback->sourceRect->toArray())->toBe(['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10])
    ->and([$fallback->pivotX, $fallback->pivotY])->toBe([10.0, 10.0]);

  // Whole-image artwork (no authored crop) reconciles the same way.
  $whole = new BattlerArtwork('a.png', 100, 120, 50, 100);
  $shrunk = $whole->clampedTo(80, 90);
  expect($shrunk->sourceRect)->toBeNull()->and([$shrunk->width, $shrunk->height])->toBe([80, 90])
    ->and([$shrunk->pivotX, $shrunk->pivotY])->toBe([40.0, 75.0]);
});

it('prepares a best-effort graphical battle when artwork changed under its authored crop', function () {
  [$battle, $catalog] = graphicalBattleFixture();
  // The catalog still describes a 200x260 crop, but the PNG on disk is
  // 143x181: the battle must prepare and render with the clamped crop.
  $stale = new BattlerArtwork('graphical-canvas/synthetic-143x181.png', 200, 260, 100.0, 260.0,
    new SpriteSourceRect(0, 0, 200, 260));
  $catalog = new BattlePresentationCatalog($catalog->arenas, ['Hero' => $stale], $catalog->enemies, ui: $catalog->ui, defaultArena: $catalog->defaultArena);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $hero = $presentation->frame()->images[1];
  expect(str_starts_with($hero->id, 'combatant-'))->toBeTrue()
    ->and($hero->sourceRect->toArray())->toBe(['x' => 0, 'y' => 0, 'width' => 143, 'height' => 181]);
});

it('renders the whole image when the authored crop no longer exists in it', function () {
  [$battle, $catalog] = graphicalBattleFixture();
  $gone = new BattlerArtwork('graphical-canvas/synthetic-143x181.png', 50, 50, 25, 50,
    new SpriteSourceRect(150, 0, 50, 50));
  $catalog = new BattlePresentationCatalog($catalog->arenas, ['Hero' => $gone], $catalog->enemies, ui: $catalog->ui, defaultArena: $catalog->defaultArena);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $hero = $presentation->frame()->images[1];
  expect($hero->sourceRect->toArray())->toBe(['x' => 0, 'y' => 0, 'width' => 143, 'height' => 181]);
});

it('accepts replacement images at the same asset path without changing character identity or catalog', function () {
  $root = sys_get_temp_dir() . '/ichiloto-replaceable-battler-' . bin2hex(random_bytes(5));
  mkdir($root);
  copy($this->root . '/graphical-canvas/synthetic-320x180.png', $root . '/arena.png');
  copy($this->root . '/graphical-canvas/synthetic-143x181.png', $root . '/twin.png');
  [$battle, , $hero] = graphicalBattleFixture();
  $catalog = require __DIR__ . '/../Fixtures/BattlePresentation/catalog.php';
  $authored = $catalog->actors['Hero'];
  $health = $hero->stats->currentHp;
  try {
    foreach (['graphical-canvas/synthetic-143x181.png', 'test-sprite.png',
      'graphical-canvas/synthetic-320x180.png'] as $replacement) {
      copy($this->root . '/' . $replacement, $root . '/hero.png');
      $frame = GraphicalBattlePresentation::prepare($battle, $catalog, $root)->frame();
      CanvasImagePreflight::inspect($frame->images, $root);
      $image = $frame->images[1];
      $image->destination->assertWithin($frame->width, $frame->height);
      expect($image->asset)->toBe('hero.png')
        ->and($image->id)->toBe('combatant-' . spl_object_id($hero))
        ->and($catalog->actors['Hero'])->toBe($authored)
        ->and($hero->actorId)->toBe('Hero')
        ->and($hero->stats->currentHp)->toBe($health)
        ->and($battle->entryRulesEvaluated())->toBeFalse();
    }
  } finally {
    foreach (['hero.png', 'twin.png', 'arena.png'] as $asset) { unlink($root . '/' . $asset); }
    rmdir($root);
  }
});

it('retains a prepared reference scale through live resolution replacements without changing gameplay or identity', function () {
  $root = sys_get_temp_dir() . '/ichiloto-live-reference-scale-' . bin2hex(random_bytes(5));
  mkdir($root);
  copy($this->root . '/graphical-canvas/synthetic-320x180.png', $root . '/arena.png');
  copy($this->root . '/graphical-canvas/synthetic-143x181.png', $root . '/twin.png');
  copy($this->root . '/graphical-canvas/synthetic-143x181.png', $root . '/hero.png');
  [$battle, , $hero] = graphicalBattleFixture();
  $legacy = require __DIR__ . '/../Fixtures/BattlePresentation/catalog.php';
  $registration = new BattleScale('Hero', 100, ['Hero' => new BattlerScale(1, .75)],
    ['Twin' => new BattlerScale(.5, .5, horizontal: true)]);
  $catalog = new BattlePresentationCatalog($legacy->arenas, $legacy->actors, $legacy->enemies,
    ui: $legacy->ui, defaultArena: $legacy->defaultArena, scale: $registration);
  $before = serialize($battle);
  try {
    $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $root);
    foreach (['graphical-canvas/synthetic-143x181.png', 'test-sprite.png',
      'graphical-canvas/synthetic-320x180.png'] as $replacement) {
      copy($this->root . '/' . $replacement, $root . '/hero.png');
      $frame = $presentation->frame();
      CanvasImagePreflight::inspect($frame->images, $root);
      $image = $frame->images[1];
      $size = PngAssetPreflight::getAvailableSize($root, 'hero.png');
      expect($image->destination->height * .75)->toEqualWithDelta(100, .000001)
        ->and($image->destination->width / $size['width'])
        ->toEqualWithDelta($image->destination->height / $size['height'], .000001)
        ->and($image->id)->toBe('combatant-' . spl_object_id($hero))
        ->and($catalog->scale)->toBe($registration)
        ->and(serialize($battle))->toBe($before);
    }
  } finally {
    foreach (['hero.png', 'twin.png', 'arena.png'] as $asset) { unlink($root . '/' . $asset); }
    rmdir($root);
  }
});

it('derives whole battler dimensions and preserves normalized pivots across replacements', function () {
  $root = sys_get_temp_dir() . '/ichiloto-whole-battler-' . bin2hex(random_bytes(5));
  mkdir($root);
  try {
    copy($this->root . '/graphical-canvas/synthetic-143x181.png', $root . '/hero.png');
    $art = BattlerArtwork::getFromPng($root, 'hero.png', 0.25, 0.9);
    expect([$art->width, $art->height])->toBe([143, 181])
      ->and($art->sourceRect)->toBeNull()
      ->and($art->pivotX)->toBe(35.75)
      ->and($art->pivotY)->toBe(162.9);
    foreach ([[80, 240], [300, 100], [20, 30]] as [$width, $height]) {
      $current = $art->clampedTo($width, $height);
      expect([$current->width, $current->height])->toBe([$width, $height])
        ->and($current->sourceRect)->toBeNull()
        ->and($current->pivotX)->toBe($width * 0.25)
        ->and($current->pivotY)->toEqualWithDelta($height * 0.9, 0.000001);
    }
    foreach ([-0.1, 1.1, INF, NAN] as $pivot) {
      expect(fn() => BattlerArtwork::getFromPng($root, 'hero.png', $pivot))->toThrow(InvalidArgumentException::class);
    }
  } finally {
    unlink($root . '/hero.png');
    rmdir($root);
  }
});

it('keeps a prepared battle usable as individual background battler and target assets disappear', function () {
  $root = sys_get_temp_dir() . '/ichiloto-live-battle-assets-' . bin2hex(random_bytes(5));
  mkdir($root . '/graphical-canvas', 0777, true);
  $assets = ['test-sprite.png', 'graphical-canvas/synthetic-320x180.png', 'graphical-canvas/synthetic-143x181.png'];
  foreach ($assets as $asset) { copy($this->root . '/' . $asset, $root . '/' . $asset); }
  try {
    [$battle, $catalog, $hero] = graphicalBattleFixture(true);
    $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $root);
    $scene = graphicalBattleScene($battle, $presentation);
    $scene->ui->fieldWindow->focusPartyBattlers([0]);
    unlink($root . '/test-sprite.png');
    $frame = $presentation->frame($scene->ui->fieldWindow, focus: 'target');
    expect($frame->images)->toHaveCount(4)
      ->and($frame->indicators)->toHaveCount(1)
      ->and($frame->indicators[0]->kind)->toBe(Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind::OUTLINE);
    unlink($root . '/graphical-canvas/synthetic-320x180.png');
    expect($presentation->frame($scene->ui->fieldWindow)->images)->toHaveCount(3);
    unlink($root . '/graphical-canvas/synthetic-143x181.png');
    $frame = $presentation->frame($scene->ui->fieldWindow);
    expect($frame->images)->toBeEmpty()->and($frame->textLayers)->not->toBeEmpty()
      ->and($hero->stats->currentHp)->toBe(100)->and($battle->entryRulesEvaluated())->toBeFalse();
    copy($this->root . '/graphical-canvas/synthetic-143x181.png', $root . '/graphical-canvas/synthetic-143x181.png');
    expect($presentation->frame()->images)->toHaveCount(3);
  } finally {
    foreach ($assets as $asset) { if (is_file($root . '/' . $asset)) { unlink($root . '/' . $asset); } }
    rmdir($root . '/graphical-canvas');
    rmdir($root);
  }
});
