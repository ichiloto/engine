<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Enumerations\BattleEngineType;
use Ichiloto\Engine\Battle\Interfaces\BattleEngineContextInterface;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\TerminalInputSource;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\ScreenTransitionCatalog;
use Ichiloto\Engine\Rendering\ScreenTransitionTreatment;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleLoader;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Battle\States\BattleRunState;
use Ichiloto\Engine\Scenes\Battle\States\BattleStartState;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';
require_once __DIR__ . '/../Fixtures/Rendering/ScreenTransitions.php';

final class EntryGameFixture extends Game
{
  public function __construct()
  {
    $this->name = 'Silent entry fixture';
    $this->width = 135;
    $this->height = 36;
    $this->sceneManager = new EntrySceneManagerFixture($this);
    $this->audioManager = new class extends AudioManager {
      public function __construct() {}
      public function playSystemSound(SystemSound $sound): void {}
      public function playBackgroundMusic(string $track, bool $loop = true): void {}
      public function stopBackgroundMusic(): void {}
    };
    $this->engine = new class($this) extends ActiveTimeBattleEngine {
      public int $starts = 0;
      public int $runs = 0;
      public function start(): void { $this->starts++; parent::start(); }
      public function run(BattleEngineContextInterface $context): void { $this->runs++; }
    };
  }
  public function __destruct() {}
  public function useBattleEngineType(BattleEngineType $type): void {}
}

final class EntrySceneManagerFixture extends SceneManager
{
  public float $now = 0;
  public ?EntryBattleFixture $battleFixture = null;
  public function __construct(Game $game)
  {
    $this->game = $game;
    $this->scenes = new ItemList(SceneInterface::class);
    $this->eventManager = EventManager::getInstance($game);
    $this->battleLoader = new class extends BattleLoader {
      public function __construct() {}
      public function newConfig(Party $party, Troop $troop, array $battleEvents, array $extraSettings = []): BattleConfig
      { return new BattleConfig($party, $troop, $battleEvents, ['firstStrike' => 'normal', ...$extraSettings]); }
    };
  }
  public function findScene(string $className): ?SceneInterface
  { return $className === BattleScene::class ? $this->battleFixture : parent::findScene($className); }
  protected function getTransitionTime(): float { return $this->now; }
  public function advance(float $seconds): void { $this->now += $seconds; $this->update(); }
}

function getEntryFixtureCamera(): Camera
{
  return new class extends Camera {
    public function __construct() {}
    public function start(): void {}
    public function stop(): void {}
    public function suspend(): void {}
    public function render(): void {}
    public function update(): void {}
  };
}

class EntryFieldFixture extends AbstractScene
{
  public string $content = 'Actual field composition';
  public int $updates = 0;
  public int $stops = 0;
  public int $suspends = 0;
  public int $resumes = 0;
  public function __construct(SceneManager $manager)
  {
    parent::__construct($manager, 'Field');
    $this->camera = getEntryFixtureCamera();
  }
  public function update(): void { $this->updates++; }
  public function stop(): void { $this->stops++; parent::stop(); }
  public function suspend(): void { $this->suspends++; parent::suspend(); }
  public function resume(): void { $this->resumes++; parent::resume(); }
  public function render(): void { Console::write($this->content, 0, 0); }
}

final class EntryOtherFixture extends EntryFieldFixture {}

final class EntryBattleFixture extends BattleScene
{
  public bool $useGraphicalField = false;
  public string $compositionId = 'prepared-battle';
  public bool $failPreparation = false;
  public bool $failComposition = false;
  public int $compositions = 0;
  public function __construct(SceneManager $manager)
  {
    parent::__construct($manager, 'Battle');
    $this->camera = getEntryFixtureCamera();
  }
  public function configure(SceneConfigurationInterface $config): void
  {
    if ($this->failPreparation) { throw new RuntimeException('Incoming preparation failed.'); }
    parent::configure($config);
  }
  protected function initializeBattleSceneStates(): void
  {
    parent::initializeBattleSceneStates();
    if ($this->useGraphicalField) {
      // Entry owns terminal-layer replacement even when graphical field drawing makes no Console writes.
      new ReflectionProperty(BattleScene::class, 'graphicalPresentation')->setValue($this,
        new ReflectionClass(\Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation::class)->newInstanceWithoutConstructor());
    }
  }
  public function getPresentationCanvas(): ?PresentationCanvas
  {
    if ($this->failComposition) { throw new RuntimeException('Incoming composition failed.'); }
    $this->compositions++;
    return new PresentationCanvas(1350, 720,
      [new CanvasImage($this->compositionId, 'prepared-battle.png', new CanvasRectangle(0, 0, 1350, 720))]);
  }
}

beforeEach(function () {
  $this->staticState = [];
  foreach ([Console::class, InputManager::class, ConfigStore::class, EventManager::class, UIManager::class] as $class) {
    $this->staticState[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  ConfigStore::put(ProjectConfig::class, new class extends ProjectConfig {
    protected function load(): array { return ['audio' => ['music' => false, 'sfx' => false], 'accessibility' => ['reducedMotion' => false]]; }
  });
  ConfigStore::put(PlaySettings::class, new PlaySettings(['screenDimensions' => ['width' => 135, 'height' => 36]]));
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  InputManager::setInputSource(new TerminalInputSource());
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 36);
  Console::clear();
  $this->root = sys_get_temp_dir() . '/entry-transition-' . bin2hex(random_bytes(5));
  mkdir($this->root . '/Data/Presentation', 0777, true);
  $data = var_export(getScreenTransitionFixture(), true);
  file_put_contents($this->root . '/' . ScreenTransitionCatalog::FILE,
    '<?php return new \\Ichiloto\\Engine\\Rendering\\ScreenTransitionCatalog(['
    . "'gilded-sweep' => new \\Ichiloto\\Engine\\Rendering\\ScreenTransitionTreatment({$data})], battle: 'gilded-sweep');");
  $this->transport = new FakeRendererTransport();
  $capabilities = ['graphical_canvas', 'canvas_compositing', 'canvas_overlay', 'window_activation'];
  $this->transport->onStart = static function ($peer) use ($capabilities): void {
    $peer->batches = [[RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => $capabilities]))]];
  };
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $this->root,
    requiredCapabilities: $capabilities), $this->transport);
  $this->game = new EntryGameFixture();
  new ReflectionProperty(UIManager::class, 'instance')->setValue(null, new class($this->game) extends UIManager {
    public function __construct(Game $game) { parent::__construct($game); }
  });
  $this->game->useRendererRuntime($this->runtime);
  $this->runtime->start('Silent fixture', 135, 36);
  $this->manager = $this->game->sceneManager;
  $this->field = new EntryFieldFixture($this->manager);
  $this->battle = $this->manager->battleFixture = new EntryBattleFixture($this->manager);
  $this->other = new EntryOtherFixture($this->manager);
  $this->manager->addScenes($this->field, $this->battle, $this->other);
  $this->manager->loadScene(EntryFieldFixture::class);
  $this->manager->render();
  $this->runtime->present($this->field);
  $this->party = new Party();
  $this->party->addMember(new Character('Entry hero', 1, new Stats(currentHp: 100)));
  $this->troop = new Troop('Entry troop');
});

afterEach(function () {
  try { $this->manager->stop(); } finally { $this->runtime->shutdown(); }
  unlink($this->root . '/' . ScreenTransitionCatalog::FILE);
  rmdir($this->root . '/Data/Presentation'); rmdir($this->root . '/Data'); rmdir($this->root);
  foreach ($this->staticState as $class => $state) {
    foreach ($state as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('retains the actual outgoing view and masks the prepared incoming battle before starting combat', function () {
  $this->manager->loadBattleScene($this->party, $this->troop);
  expect($this->manager->currentScene)->toBe($this->field)->and($this->manager->hasSceneTransition())->toBeTrue();
  $this->manager->advance(30);
  $this->runtime->present($this->field);
  $outgoing = RetainedFrameState::getLatestFrame($this->transport->sent);
  expect($outgoing['textLayers'][0]['runs'][0]['text'])->toStartWith('Actual field composition')
    ->and($outgoing['canvas']['composites'][0]['operations'][0]['masks'])->toBe([])
    ->and($this->game->engine->starts)->toBe(0)->and($this->field->updates)->toBe(0);
  $this->manager->advance(.08);
  $this->runtime->present($this->manager->currentScene);
  $incoming = RetainedFrameState::getLatestFrame($this->transport->sent);
  expect($this->manager->currentScene)->toBe($this->battle)->and($this->battle->state)->toBeInstanceOf(BattleStartState::class)
    ->and($this->field->suspends)->toBe(1)->and($this->game->engine->starts)->toBe(0)
    ->and($incoming['textLayers'])->toBe([])->and($incoming['canvas']['images'][0]['id'])->toBe('prepared-battle')
    ->and($incoming['canvas']['composites'][0]['operations'][0]['opacity'])->toBe(1.0);
  $compositions = $this->battle->compositions;
  $this->manager->advance(.19);
  $this->runtime->present($this->battle);
  expect($this->battle->compositions)->toBe($compositions)->and($this->game->engine->runs)->toBe(0);
  $this->manager->advance(.19);
  $this->runtime->present($this->battle);
  expect($this->manager->hasSceneTransition())->toBeFalse()->and($this->game->engine->starts)->toBe(1)
    ->and($this->battle->state)->toBeInstanceOf(BattleRunState::class)
    ->and(RetainedFrameState::getLatestFrame($this->transport->sent)['canvas'])->not->toHaveKey('composites');
  $this->manager->returnFromBattleScene();
  expect($this->manager->currentScene)->toBe($this->field)->and($this->field->resumes)->toBe(1)->and($this->field->stops)->toBe(0);
});

it('starts combat immediately without the old terminal intro under Off or reduced motion', function (bool $reduced) {
  ConfigStore::get(ProjectConfig::class)->set($reduced ? 'accessibility.reducedMotion' : 'ui.transitions.style', $reduced ? true : 'none');
  $this->manager->loadBattleScene($this->party, $this->troop);
  expect($this->manager->hasSceneTransition())->toBeFalse()->and($this->manager->currentScene)->toBe($this->battle)
    ->and($this->game->engine->starts)->toBe(1)->and($this->battle->state)->toBeInstanceOf(BattleRunState::class);
  $this->runtime->present($this->battle);
  expect(RetainedFrameState::getLatestFrame($this->transport->sent)['canvas'])->not->toHaveKey('composites');
})->with(['Off' => [false], 'reduced motion' => [true]]);

it('enables an authored battle handoff independently of doorway transitions', function () {
  ConfigStore::get(ProjectConfig::class)->set('ui.transitions.style', 'none');
  ConfigStore::get(ProjectConfig::class)->set('ui.transitions.battle', true);
  $this->manager->loadBattleScene($this->party, $this->troop);
  expect($this->manager->hasSceneTransition())->toBeTrue()
    ->and($this->manager->currentScene)->toBe($this->field)
    ->and($this->game->engine->starts)->toBe(0);
});

it('honours an explicit battle Off setting without disabling doorway transitions', function () {
  ConfigStore::get(ProjectConfig::class)->set('ui.transitions.style', 'wipe');
  ConfigStore::get(ProjectConfig::class)->set('ui.transitions.battle', false);
  $this->manager->loadBattleScene($this->party, $this->troop);
  expect($this->manager->hasSceneTransition())->toBeFalse()
    ->and($this->battle->state)->toBeInstanceOf(BattleRunState::class);
});

it('replaces untiled field text at battle handoff rather than importing it into the battle UI', function (string $mode, bool $graphical) {
  ConfigStore::get(ProjectConfig::class)->set('ui.transitions.style', $mode === 'off' ? 'none' : 'wipe');
  ConfigStore::get(ProjectConfig::class)->set('accessibility.reducedMotion', $mode === 'reduced');
  $this->battle->useGraphicalField = $graphical;
  Console::withLayer('map:untiled-floor', fn() => Console::write('UNTILED FIELD GLYPHS', 20, 10), -100);
  Console::replaceOverlay('persistent-notice', ['NOTICE SURVIVES'], 2, 2, 4000);
  $this->manager->loadBattleScene($this->party, $this->troop);
  if ($mode === 'normal') {
    expect(implode('', Console::snapshot()->rows))->toContain('UNTILED FIELD GLYPHS');
    $this->manager->advance(1);
    $this->manager->advance(.08);
    $this->manager->advance(.38);
  }
  expect(array_column(Console::presentationSnapshot()->textLayers, 'id'))->not->toContain('map:untiled-floor')
    ->and(implode('', Console::snapshot()->rows))->not->toContain('UNTILED FIELD GLYPHS', 'Actual field composition')
    ->and(implode('', Console::snapshot()->rows))->toContain('NOTICE SURVIVES');
})->with(['normal', 'off', 'reduced'])->with([false, true]);

it('rebuilds each encounter and releases its cover before returning to the same field', function (string $mode) {
  ConfigStore::get(ProjectConfig::class)->set('ui.transitions.style', $mode === 'off' ? 'none' : 'default');
  ConfigStore::get(ProjectConfig::class)->set('accessibility.reducedMotion', $mode === 'reduced');
  $previousUi = null;
  for ($encounter = 1; $encounter <= 3; $encounter++) {
    $this->field->content = 'Current field ' . $encounter;
    $this->battle->compositionId = 'encounter-' . $encounter;
    $this->manager->render();
    $this->runtime->present($this->field);
    $this->manager->loadBattleScene($this->party, $this->troop);
    if ($mode === 'normal') {
      expect($this->manager->currentScene)->toBe($this->field)
        ->and($this->game->engine->starts)->toBe($encounter - 1);
      $this->manager->advance(1);
      $this->runtime->present($this->field);
      $covered = RetainedFrameState::getLatestFrame($this->transport->sent);
      expect($covered['textLayers'][0]['runs'][0]['text'])->toStartWith($this->field->content);
      $this->manager->advance(.08);
      $this->runtime->present($this->battle);
      $incoming = RetainedFrameState::getLatestFrame($this->transport->sent);
      expect($incoming['canvas']['images'][0]['id'])->toBe($this->battle->compositionId)
        ->and($incoming['textLayers'])->toBe([])
        ->and($this->game->engine->starts)->toBe($encounter - 1);
      $this->manager->advance(.38);
    }
    $this->runtime->present($this->battle);
    $running = RetainedFrameState::getLatestFrame($this->transport->sent);
    expect($this->manager->hasSceneTransition())->toBeFalse()
      ->and($this->manager->currentScene)->toBe($this->battle)
      ->and($this->battle->state)->toBeInstanceOf(BattleRunState::class)
      ->and($this->battle->ui)->not->toBe($previousUi)
      ->and($this->battle->result)->toBeNull()
      ->and($this->battle->shouldLoadGameOver)->toBeFalse()
      ->and($this->game->engine->starts)->toBe($encounter)
      ->and($running['canvas']['images'][0]['id'])->toBe($this->battle->compositionId)
      ->and($running['canvas'])->not->toHaveKey('composites');
    $previousUi = $this->battle->ui;
    $this->battle->shouldLoadGameOver = true;
    $this->manager->returnFromBattleScene();
    $this->manager->render();
    $this->runtime->present($this->field);
    $returned = RetainedFrameState::getLatestFrame($this->transport->sent);
    expect($this->manager->currentScene)->toBe($this->field)
      ->and($this->field->resumes)->toBe($encounter)
      ->and($this->field->suspends)->toBe($encounter)
      ->and($this->field->stops)->toBe(0)
      ->and($returned)->not->toHaveKey('canvas')
      ->and($returned['textLayers'][0]['runs'][0]['text'])->toStartWith($this->field->content);
  }
})->with(['normal transition' => 'normal', 'transitions off' => 'off', 'reduced motion' => 'reduced']);

it('pauses on focus loss and resumes without charging unfocused time to the sweep', function () {
  $this->manager->loadBattleScene($this->party, $this->troop);
  $this->manager->advance(.15);
  $this->runtime->present($this->field);
  $before = RetainedFrameState::getLatestFrame($this->transport->sent)['canvas'];
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"window_activation","active":false}')];
  $this->runtime->pump(); $this->manager->advance(100); $this->runtime->present($this->field);
  expect(RetainedFrameState::getLatestFrame($this->transport->sent)['canvas'])->toBe($before)
    ->and($this->manager->currentScene)->toBe($this->field)->and($this->field->updates)->toBe(0);
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"window_activation","active":true}')];
  $this->runtime->pump(); $this->manager->advance(100); $this->runtime->present($this->field);
  expect(RetainedFrameState::getLatestFrame($this->transport->sent)['canvas'])->toBe($before);
  $this->manager->advance(.21); $this->runtime->present($this->field);
  $this->manager->advance(.08); $this->runtime->present($this->battle);
  $this->manager->advance(.38);
  expect($this->manager->hasSceneTransition())->toBeFalse()->and($this->game->engine->starts)->toBe(1);
});

it('cancels an abandoned entry without loading combat or leaving a renderer overlay', function () {
  $this->manager->loadBattleScene($this->party, $this->troop);
  $this->manager->advance(.15); $this->runtime->present($this->field);
  $this->manager->loadScene(EntryOtherFixture::class);
  $this->manager->render(); $this->runtime->present($this->other);
  expect($this->manager->hasSceneTransition())->toBeFalse()->and($this->game->engine->starts)->toBe(0)
    ->and($this->field->stops)->toBe(1)->and($this->field->suspends)->toBe(0)
    ->and(RetainedFrameState::getLatestFrame($this->transport->sent))->not->toHaveKey('canvas');
  $this->manager->loadBattleScene($this->party, $this->troop);
  expect($this->manager->hasSceneTransition())->toBeTrue();
});

it('releases transition resources on a preparation error and preserves the original failure', function () {
  $this->battle->failPreparation = true;
  $this->manager->loadBattleScene($this->party, $this->troop);
  $this->manager->advance(1); $this->runtime->present($this->field);
  expect(fn() => $this->manager->advance(.08))->toThrow(RuntimeException::class, 'Incoming preparation failed.');
  expect($this->manager->hasSceneTransition())->toBeFalse()->and($this->game->engine->starts)->toBe(0);
  $this->manager->loadScene(EntryOtherFixture::class);
  $this->manager->render(); $this->runtime->present($this->other);
  expect(RetainedFrameState::getLatestFrame($this->transport->sent))->not->toHaveKey('canvas');
});

it('cleans up a failure preparing the covered composition without starting combat', function () {
  $this->battle->failComposition = true;
  $this->manager->loadBattleScene($this->party, $this->troop);
  $this->manager->advance(1); $this->runtime->present($this->field);
  expect(fn() => $this->manager->advance(.08))->toThrow(RuntimeException::class, 'Incoming composition failed.');
  expect($this->manager->hasSceneTransition())->toBeFalse()->and($this->game->engine->starts)->toBe(0);
  $this->manager->loadScene(EntryOtherFixture::class);
  $this->manager->render(); $this->runtime->present($this->other);
  expect(RetainedFrameState::getLatestFrame($this->transport->sent))->not->toHaveKey('canvas');
});
