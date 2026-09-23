<?php

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasUiAdapter;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
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

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function graphicalBattleFixture(bool $skinned = false, bool $directionalCursor = false): array
{
  $party = new Party();
  $hero = new Character('Hero', 1, new Stats(currentHp: 100, totalHp: 100, attack: 32));
  $party->addMember($hero);
  $enemies = [];
  foreach ([0, 1] as $index) {
    $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
    foreach (['name' => 'Twin', 'level' => 1, 'stats' => new Stats(currentHp: 100, totalHp: 100),
      'position' => new Vector2($index * 10 + 1, 1), 'image' => ['ASCII MUST NOT DRAW'], 'imagePath' => ''] as $key => $value) {
      new ReflectionProperty(Enemy::class, $key)->setValue($enemy, $value);
    }
    $enemies[] = $enemy;
  }
  $battle = new BattleConfig($party, new Troop('Twins', $enemies), entryExecutionId: 'fixture-entry');
  $art = new BattlerArtwork('graphical-canvas/synthetic-143x181.png', 143, 181, 71.5, 181);
  $skin = null;
  if ($skinned) {
    $textures = array_fill_keys(['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued', 'acting'],
      new CanvasNineSlice('test-sprite.png', new SpriteSourceRect(0, 0, 32, 48)));
    $colors = array_fill_keys(['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'],
      PresentationColor::rgb(200, 200, 200));
    $skin = new BattleUiSkin($textures, $colors, $directionalCursor
      ? new BattleTargetCursor(array_fill_keys(['above', 'left', 'right'],
        new CanvasNineSlice('test-sprite.png', new SpriteSourceRect(0, 0, 32, 32)))) : null);
  }
  $arena = new BattleArenaDefinition(1350, 720,
    new CanvasImage('arena', 'graphical-canvas/synthetic-320x180.png', new CanvasRectangle(0, 0, 1350, 720)),
    [new BattlerSlot(969, 265, 143, 181), new BattlerSlot(1137, 383, 137, 179), new BattlerSlot(969, 501, 151, 183)],
    [new BattlerSlot(375, 467, 173, 197), new BattlerSlot(497, 233, 197, 119)], skin: $skin,
    feedbackArea: $skinned ? new CanvasRectangle(0, 80, 1350, 452) : null);
  return [$battle, new BattlePresentationCatalog(['Twins' => $arena], ['Hero' => $art], ['Twin' => $art]), $hero, $enemies];
}

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
  foreach ([Console::class, ConfigStore::class, InputManager::class, Debug::class] as $class) { $this->statics[$class] = new ReflectionClass($class)->getStaticProperties(); }
  $this->logRoot = sys_get_temp_dir() . '/ichiloto-battle-logs-' . bin2hex(random_bytes(5));
  Debug::configure(['log_directory' => $this->logRoot]);
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 36);
  Console::setLayerTracking(true);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  ConfigStore::put(Ichiloto\Engine\Util\Config\ProjectConfig::class, new PlaySettings([]));
  $this->root = __DIR__ . '/../Fixtures/Renderer';
});

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

it('selects an explicit encounter arena without leaking it into the next battle', function () {
  [$fixture, $catalog] = graphicalBattleFixture();
  $legacy = $catalog->arenas['Twins'];
  $yard = new BattleArenaDefinition(1350, 720,
    new CanvasImage('yard', $legacy->background->asset, $legacy->background->destination),
    $legacy->partySlots, $legacy->enemySlots);
  $catalog = new BattlePresentationCatalog(['Twins' => $legacy, 'arena.yard' => $yard], $catalog->actors, $catalog->enemies);
  $battle = new BattleConfig($fixture->party, $fixture->troop, settings: ['battleArena' => 'arena.yard']);

  expect(GraphicalBattlePresentation::prepare($battle, $catalog, $this->root)->arena)->toBe($yard)
    ->and(GraphicalBattlePresentation::prepare($fixture, $catalog, $this->root)->arena)->toBe($legacy)
    ->and($battle->entryRulesEvaluated())->toBeFalse();
  $restored = unserialize(serialize(new BattleConfig($fixture->party, new Troop('Twins'), settings: $battle->settings)));
  expect($catalog->arenaFor($restored))->toBe($yard);
  $unconfigured = new BattleConfig($fixture->party, new Troop('Deferred area'));
  expect($catalog->arenaFor($unconfigured))->toBeNull();
});

it('rejects explicit invalid or absent arena keys instead of falling back to troop art', function (mixed $key, string $error) {
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
]);

it('resolves troop formations inside the selected arena without changing location or shared state', function () {
  [$fixture, $catalog] = graphicalBattleFixture();
  $base = $catalog->arenas['Twins'];
  $slots = [new BattlerSlot(350, 400, 160, 230), new BattlerSlot(530, 470, 120, 100)];
  $road = new BattleArenaDefinition(1350, 720, $base->background, $base->partySlots, $base->enemySlots,
    enemySlotsByTroop: ['troop.mixed' => $slots, 'Localized name' => array_reverse($slots)]);
  $catalog = new BattlePresentationCatalog(['Twins' => $base, 'arena.road' => $road], $catalog->actors, $catalog->enemies);
  $troop = new Troop('Localized name', $fixture->troop->members->toArray(), definitionId: 'troop.mixed');
  $battle = new BattleConfig($fixture->party, $troop, settings: ['battleArena' => 'arena.road']);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);

  expect($presentation->arena->enemySlots)->toBe($slots)
    ->and($presentation->arena->background)->toBe($base->background)
    ->and($presentation->arena->partySlots)->toBe($base->partySlots)
    ->and($road->enemySlots)->toBe($base->enemySlots)
    ->and($road->enemySlotsByTroop['troop.mixed'])->toBe($slots)
    ->and($battle->entryRulesEvaluated())->toBeFalse();
  $images = $presentation->frame()->images;
  foreach ($slots as $index => $slot) {
    expect($images[$index + 2]->destination)->toEqual($slot->place($catalog->enemies['Twin']));
  }
  expect($presentation->frame()->toArray())->toBe($presentation->frame()->toArray());
  $unchanged = new BattleConfig($fixture->party, $fixture->troop, settings: ['battleArena' => 'arena.road']);
  expect($catalog->arenaFor($unchanged))->toBe($road)
    ->and($catalog->arenaFor($fixture))->toBe($base)
    ->and($catalog->arenaFor(new BattleConfig($fixture->party, $troop)))->toBeNull();

  // Current catalog data, not a serialized arena, resolves a restored encounter.
  $restored = unserialize(serialize(new BattleConfig($fixture->party,
    new Troop('Renamed troop', definitionId: 'troop.mixed'), settings: $battle->settings)));
  expect($catalog->arenaFor($restored)->enemySlots)->toBe($slots);
});

it('uses historical formation names only when the troop has no authored identity', function () {
  [$fixture, $catalog] = graphicalBattleFixture();
  $base = $catalog->arenas['Twins'];
  $slots = array_reverse($base->enemySlots);
  $arena = new BattleArenaDefinition(1350, 720, $base->background, $base->partySlots, $base->enemySlots,
    enemySlotsByTroop: ['Twins' => $slots]);
  $catalog = new BattlePresentationCatalog(['Twins' => $arena, 'arena.road' => $arena], $catalog->actors, $catalog->enemies);
  expect($catalog->arenaFor($fixture)->enemySlots)->toBe($slots);
  $identified = new BattleConfig($fixture->party, new Troop('Twins', definitionId: 'troop.other'),
    settings: ['battleArena' => 'arena.road']);
  expect($catalog->arenaFor($identified))->toBe($arena);
});

it('preserves arena and inherited UI geometry when applying a troop formation', function (bool $ownSkin) {
  [$fixture, $catalog] = graphicalBattleFixture(true);
  $base = $catalog->arenas['Twins'];
  $slots = array_reverse($base->enemySlots);
  $ui = new BattleCanvasLayout(1350, 720, skin: $base->skin, feedbackArea: $base->feedbackArea);
  $arena = new BattleArenaDefinition(1350, 720, $base->background, $base->partySlots, $base->enemySlots,
    uiCellWidth: 9, uiCellHeight: 18, skin: $ownSkin ? $base->skin : null,
    feedbackArea: $ownSkin ? $base->feedbackArea : null, enemySlotsByTroop: ['Twins' => $slots]);
  $catalog = new BattlePresentationCatalog(['Twins' => $arena], $catalog->actors, $catalog->enemies, ui: $ui);
  $resolved = GraphicalBattlePresentation::prepare($fixture, $catalog, $this->root)->arena;
  expect($resolved->skin)->toBe($base->skin)
    ->and($resolved->feedbackArea)->toBe($base->feedbackArea)
    ->and($resolved->uiGrid)->toEqual($arena->uiGrid)
    ->and($resolved->enemySlots)->toBe($slots)
    ->and($resolved->background)->toBe($base->background)
    ->and($arena->withDefaultUi($ui)->getForTroop('Twins')->enemySlots)->toBe($slots);
})->with([true, false]);

it('rejects invalid troop formation metadata', function (array $formations) {
  [$fixture, $catalog] = graphicalBattleFixture();
  $base = $catalog->arenas['Twins'];
  expect(fn() => new BattleArenaDefinition(1350, 720, $base->background, $base->partySlots, $base->enemySlots,
    enemySlotsByTroop: $formations))->toThrow(InvalidArgumentException::class);
})->with([
  'numeric identity' => [[0 => []]],
  'empty identity' => [['' => []]],
  'control in identity' => [["troop\ninvalid" => []]],
  'not a slot list' => [['Twins' => 'invalid']],
  'sparse slot list' => [['Twins' => [1 => new BattlerSlot(350, 400, 100, 100)]]],
  'untyped slot' => [['Twins' => [['x' => 350, 'y' => 400]]]],
  'too many slots' => [['Twins' => array_fill(0, 65, new BattlerSlot(350, 400, 100, 100))]],
]);

it('preflights the selected formation instead of silently reverting to default slots', function (array $slots, string $error) {
  [$fixture, $catalog] = graphicalBattleFixture();
  $base = $catalog->arenas['Twins'];
  $arena = new BattleArenaDefinition(1350, 720, $base->background, $base->partySlots, $base->enemySlots,
    enemySlotsByTroop: ['Twins' => $slots]);
  $catalog = new BattlePresentationCatalog(['Twins' => $arena], $catalog->actors, $catalog->enemies);
  expect(fn() => GraphicalBattlePresentation::prepare($fixture, $catalog, $this->root))->toThrow($error)
    ->and($fixture->entryRulesEvaluated())->toBeFalse();
})->with([
  'empty authored formation' => [[], RuntimeException::class],
  'missing member slot' => [[new BattlerSlot(350, 400, 100, 100)], RuntimeException::class],
  'out of canvas' => [[new BattlerSlot(1400, 400, 100, 100), new BattlerSlot(530, 470, 120, 100)], InvalidArgumentException::class],
]);

it('detaches references in authored formation mappings', function () {
  [$fixture, $catalog] = graphicalBattleFixture();
  $base = $catalog->arenas['Twins'];
  $slot = new BattlerSlot(350, 400, 100, 100);
  $slots = [&$slot];
  $formations = ['Twins' => &$slots];
  $arena = new BattleArenaDefinition(1350, 720, $base->background, $base->partySlots, $base->enemySlots,
    enemySlotsByTroop: $formations);
  $slot = new BattlerSlot(530, 470, 120, 100);
  $slots = [];
  expect($arena->getForTroop('Twins')->enemySlots)->toHaveCount(1)
    ->and($arena->getForTroop('Twins')->enemySlots[0]->x)->toBe(350.0);
});

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

it('keeps repeated enemy instance IDs through target reorder feedback and removal', function (bool $override) {
  [$battle, $catalog, $hero, $enemies] = graphicalBattleFixture();
  if ($override) {
    $base = $catalog->arenas['Twins'];
    $arena = new BattleArenaDefinition(1350, 720, $base->background, $base->partySlots, $base->enemySlots,
      enemySlotsByTroop: ['Twins' => array_reverse($base->enemySlots)]);
    $catalog = new BattlePresentationCatalog(['Twins' => $arena], $catalog->actors, $catalog->enemies);
  }
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $scene = graphicalBattleScene($battle, $presentation);
  $field = $scene->ui->fieldWindow;
  $field->focusTroopBattlers([1, 0]);
  $field->stepPartyBattlerForward($hero, 0);
  $first = $presentation->frame($field);
  $ids = array_map(fn($image) => $image->id, $first->images);
  expect(array_unique($ids))->toHaveCount(4)
    ->and($first->indicators)->toHaveCount(3);
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
})->with([false, true]);

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
  $layers = BattleCanvasUiAdapter::collect($scene, $presentation->arena);
  $text = implode('', array_map(fn($layer) => implode('', array_map(fn($run) => $run->text, $layer->runs)), $layers));
  expect($text)->toContain('Target: Twin')->not->toContain('NOT A UI WINDOW')->not->toContain('ASCII MUST NOT DRAW');
  expect(array_all($layers, fn($layer) => array_all($layer->runs, fn($run) => $run->background !== null)))->toBeTrue();
  $scene->ui->hideMessage();
  $scene->ui->hideControls();
  expect(BattleCanvasUiAdapter::collect($scene, $presentation->arena))->toBe([]);
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
  $arena = $catalog->arenas['Twins'];
  $layout = new BattleCanvasLayout(1350, 720, skin: $arena->skin, feedbackArea: $arena->feedbackArea);
  $catalog = new BattlePresentationCatalog([], [], [], ui: $layout);
  expect(GraphicalBattlePresentation::prepare($battle, $catalog, '/no-artwork-required'))->toBeNull()
    ->and($catalog->requiredCapabilities())->toBe(['graphical_canvas', 'canvas_clip_opacity', 'canvas_glyph_effects']);
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

it('applies the project UI to an authored arena without replacing its art or explicit skin', function () {
  [$battle, $plain] = graphicalBattleFixture();
  [, $skinned] = graphicalBattleFixture(skinned: true);
  $arena = $skinned->arenas['Twins'];
  $layout = new BattleCanvasLayout(1350, 720, skin: $arena->skin, feedbackArea: $arena->feedbackArea);
  $inherited = new BattlePresentationCatalog($plain->arenas, $plain->actors, $plain->enemies, ui: $layout);
  $presentation = GraphicalBattlePresentation::prepare($battle, $inherited, $this->root);
  expect($presentation->arena->skin)->toBe($layout->skin)
    ->and($presentation->arena->feedbackArea)->toBe($layout->feedbackArea)
    ->and($presentation->frame()->images[0])->toBe($plain->arenas['Twins']->background)
    ->and($arena->withDefaultUi($layout))->toBe($arena);
  expect(fn() => new BattlePresentationCatalog([], [], [], ui: new BattleCanvasLayout(1350, 720)))
    ->toThrow(InvalidArgumentException::class, 'requires a skin');
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
  $ui = BattleCanvasUiAdapter::collect($scene, $presentation->arena);
  expect($ui)->toBe([]);
  $hud = BattleHudSnapshot::fromScreen($scene->ui);
  expect($hud->messageRows)->toBe(2);
  $frame = $presentation->frame($field, $ui, $hud, 'target', 0);
  $images = array_column($frame->images, null, 'id');
  expect($images)->toHaveKeys(['field-cursor-1-1', 'queued-' . spl_object_id($enemies[0]) . '-1-1',
    'acting-' . spl_object_id($hero) . '-1-1'])
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
    ->and($images)->toHaveKeys(['queued-' . spl_object_id($enemies[0]) . '-1-1', 'acting-' . spl_object_id($hero) . '-1-1'])
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
  $missing = new BattlePresentationCatalog($catalog->arenas, $catalog->actors, []);
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

it('honours the existing reserve fallback without introducing a fourth active image', function () {
  [$battle, $catalog] = graphicalBattleFixture();
  foreach (['Second', 'Third', 'Reserve'] as $name) { $battle->party->addMember(new Character($name, 1, new Stats(currentHp: 100, totalHp: 100))); }
  $actors = $catalog->actors;
  foreach (['Second', 'Third', 'Reserve'] as $name) { $actors[$name] = $actors['Hero']; }
  $presentation = GraphicalBattlePresentation::prepare($battle, new BattlePresentationCatalog($catalog->arenas, $actors, $catalog->enemies), $this->root);
  expect($presentation->frame()->images)->toHaveCount(6);
  $members = $battle->party->members->toArray();
  foreach (array_slice($members, 0, 3) as $member) { $member->stats->currentHp = 0; }
  $frame = $presentation->frame();
  expect($frame->images)->toHaveCount(4)
    ->and($frame->images[1]->id)->toBe('combatant-' . spl_object_id($members[3]));
});

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
    expect($transport->sent[0]->payload['canvas']['images'])->toHaveCount(4)
      ->and($transport->sent[0]->payload['textLayers'])->toBe([]);
    expect($runtime->present($scene))->toBeFalse();
    Console::write('FIELD', 1, 1);
    $runtime->present(null);
    expect($transport->sent[1]->payload)->not->toHaveKey('canvas');
    expect(implode('', array_column($transport->sent[1]->payload['textLayers'][0]['runs'], 'text')))->toContain('FIELD');
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

it('configures shared UI for consecutive encounters while terminal ignores optional assets', function (bool $native, bool $results) {
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
      $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["graphical_canvas","sprite_source_rect","canvas_clip_opacity","canvas_glyph_effects"]}')];
      $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $root,
        requiredCapabilities: ['graphical_canvas', 'sprite_source_rect', 'canvas_clip_opacity', 'canvas_glyph_effects']), $transport);
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
        ->and($scene->resultsSkin !== null)->toBe($native && $results)
        ->and($scene->getPresentationCanvas())->toBeNull(); // Start transition still owns its frame.
    }
  } finally {
    $runtime?->shutdown();
    if ($native) { unlink($root . '/skin.png'); }
    if ($native && $results) {
      foreach (['Hero', 'Second', 'Third', 'Fourth'] as $id) { unlink($root . '/' . $id . '.png'); }
    }
    unlink($file); rmdir(dirname($file)); rmdir($root . '/Data'); rmdir($root);
  }
})->with([true, false])->with([true, false]);

it('logs unusable shared UI assets or capabilities and still starts combat', function (?string $missing) {
  $root = sys_get_temp_dir() . '/ichiloto-shared-ui-invalid-' . bin2hex(random_bytes(5));
  $file = $root . '/' . BattlePresentationCatalog::FILE;
  mkdir(dirname($file), 0777, true);
  copy(__DIR__ . '/../Fixtures/BattlePresentation/shared-ui.php', $file);
  if ($missing !== null) { copy($this->root . '/test-sprite.png', $root . '/skin.png'); }
  $capabilities = array_values(array_diff(['graphical_canvas', 'sprite_source_rect', 'canvas_clip_opacity', 'canvas_glyph_effects'], [$missing]));
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

it('requires crop capability and retains party KO feedback without changing health', function () {
  [$battle, $catalog, $hero] = graphicalBattleFixture();
  $art = new BattlerArtwork('graphical-canvas/synthetic-143x181.png', 143, 181, 71.5, 181, new SpriteSourceRect(0, 0, 143, 181));
  $catalog = new BattlePresentationCatalog($catalog->arenas, ['Hero' => $art], $catalog->enemies);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  expect($presentation->requiredCapabilities())->toBe(['graphical_canvas', 'sprite_source_rect']);
  $scene = graphicalBattleScene($battle, $presentation);
  $hero->stats->currentHp = 0;
  $scene->ui->fieldWindow->focusPartyBattlers([0]);
  $frame = $presentation->frame($scene->ui->fieldWindow);
  expect($frame->images[1]->opacity)->toBe(0.4)
    ->and(array_any($frame->textLayers, fn($layer) => array_any($layer->runs, fn($run) => $run->text === 'KO')))->toBeTrue()
    ->and($frame->indicators)->toHaveCount(1)
    ->and($hero->stats->currentHp)->toBe(0);
});

it('places selected ally damage and healing feedback together clear of a visible action banner', function ($value, $color) {
  [$battle, $catalog, $hero] = graphicalBattleFixture();
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $scene = graphicalBattleScene($battle, $presentation);
  $scene->ui->showMessage('An action is resolving');
  $scene->ui->showControls();
  $scene->ui->fieldWindow->focusPartyBattlers([0]);
  $scene->ui->fieldWindow->showStatChangePopup($hero, [['text' => $value, 'color' => $color], ['text' => 'RESULT']]);
  $ui = BattleCanvasUiAdapter::collect($scene, $presentation->arena);
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
  $catalog = new BattlePresentationCatalog($catalog->arenas, ['Hero' => $stale], $catalog->enemies);
  $presentation = GraphicalBattlePresentation::prepare($battle, $catalog, $this->root);
  $hero = $presentation->frame()->images[1];
  expect(str_starts_with($hero->id, 'combatant-'))->toBeTrue()
    ->and($hero->sourceRect->toArray())->toBe(['x' => 0, 'y' => 0, 'width' => 143, 'height' => 181]);
});

it('renders the whole image when the authored crop no longer exists in it', function () {
  [$battle, $catalog] = graphicalBattleFixture();
  $gone = new BattlerArtwork('graphical-canvas/synthetic-143x181.png', 50, 50, 25, 50,
    new SpriteSourceRect(150, 0, 50, 50));
  $catalog = new BattlePresentationCatalog($catalog->arenas, ['Hero' => $gone], $catalog->enemies);
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
