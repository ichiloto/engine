<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Interfaces\BattleEngineContextInterface;
use Ichiloto\Engine\Battle\Presentation\BattlePauseMenu;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\InputSourceInterface;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Battle\States\BattlePauseState;
use Ichiloto\Engine\Scenes\Battle\States\BattleRunState;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

final class PauseInputFixture implements InputSourceInterface
{
  public ?KeyCode $key = null;
  public function poll(): ?KeyCode { $key = $this->key; $this->key = null; return $key; }
  public function reset(bool $drainBufferedInput = false): void { if ($drainBufferedInput) { $this->key = null; } }
}

final class PauseGameFixture extends Game
{
  public int $quits = 0;
  public function __construct()
  {
    $this->sceneManager = new ReflectionClass(SceneManager::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(SceneManager::class, 'game')->setValue($this->sceneManager, $this);
    new ReflectionProperty(SceneManager::class, 'scenes')->setValue($this->sceneManager, new ItemList(SceneInterface::class));
    $this->engine = new class($this) extends ActiveTimeBattleEngine {
      public int $starts = 0;
      public int $runs = 0;
      public function start(): void { $this->starts++; parent::start(); }
      public function run(BattleEngineContextInterface $context): void
      {
        $this->runs++;
        $this->progressGauges($this->turnStateExecutionContext);
      }
      public function snapshot(): array
      {
        return [$this->gaugeValues, $this->readyBattlers, $this->gaugeTime, $this->readyTimes,
          $this->turnStateExecutionContext, $this->state, $this->turnQueue];
      }
    };
  }
  public function __destruct() {}
  public function quit(): void
  {
    $this->quits++;
    new ReflectionProperty(Game::class, 'terminalCleanedUp')->setValue($this, true);
    $this->sceneManager->stop();
  }
}

final class PauseBattleFixture extends BattleScene
{
  public float $now = 0;
  public int $baseFrames = 0;
  public bool $graphical = false;
  public ?PresentationCanvas $graphicalFrame = null;
  public function __construct(PauseGameFixture $game)
  {
    $this->sceneManager = $game->sceneManager;
    $this->eventManager = EventManager::getInstance($game);
    $this->camera = new class extends Camera {
      public int $updates = 0;
      public function __construct() {}
      public function start(): void {}
      public function update(): void { $this->updates++; }
      public function stop(): void {}
      public function suspend(): void {}
      public function resizeViewport(int $width, int $height): void {}
    };
    $party = new Party();
    $party->addMember(new Character('Pause Hero', 1, new Stats(currentHp: 100, speed: 10)));
    $this->config = new BattleConfig($party, new Troop('Pause test'), settings: ['firstStrike' => 'normal']);
    $this->ui = new class($this) extends BattleScreen {
      public int $updates = 0;
      public function update(): void { $this->updates++; parent::update(); }
    };
    $this->sceneStateContext = new SceneStateContext($this);
    $this->runState = new BattleRunState($this->sceneStateContext);
    $this->pauseState = new class($this->sceneStateContext) extends BattlePauseState {
      protected function createMenu(): BattlePauseMenu
      {
        return new BattlePauseMenu(clock: fn(): float => $this->scene->now);
      }
    };
    $game->sceneManager->currentScene = $this;
    $game->sceneManager->addScenes($this);
    $this->setState($this->runState);
    $this->start();
  }
  public function getPresentationCanvas(): ?PresentationCanvas
  {
    if ($this->state instanceof BattlePauseState) { return parent::getPresentationCanvas(); }
    $this->baseFrames++;
    return $this->graphical ? ($this->graphicalFrame ?? new PresentationCanvas(1350, 720)) : null;
  }
}

function pauseKey(PauseBattleFixture $scene, PauseInputFixture $source, ?KeyCode $key = null, float $advance = 0): void
{
  $scene->now += $advance;
  $source->key = $key;
  InputManager::handleInput();
  $scene->update();
}

beforeEach(function () {
  $this->statics = [];
  foreach ([Console::class, InputManager::class, EventManager::class, ConfigStore::class, Time::class] as $class) {
    $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->input = InputManager::getInputSource();
  $this->bindings = InputManager::getBindings();
  $this->delta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
  $this->event = new ReflectionProperty(EventManager::class, 'instance')->getValue();
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  $this->configs = new ReflectionProperty(ConfigStore::class, 'store')->getValue();
  new ReflectionProperty(ConfigStore::class, 'store')->setValue(null, []);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  putSceneAudioConfig([]);
  Console::setTerminalOutputEnabled(false);
  new ReflectionProperty(Console::class, 'terminalHandedBack')->setValue(null, false);
  Console::syncDimensions(135, 36);
  Console::clear();
  Console::setLayerTracking(true);
  $this->source = new PauseInputFixture();
  InputManager::setInputSource($this->source);
  InputManager::setBindings([
    'pause' => ['keys' => [KeyCode::P]], 'confirm' => ['keys' => [KeyCode::ENTER]],
    'cancel' => ['keys' => [KeyCode::C]], 'quit' => ['keys' => [KeyCode::Q]],
    'up' => ['keys' => [KeyCode::UP]], 'down' => ['keys' => [KeyCode::DOWN]],
    'left' => ['keys' => [KeyCode::LEFT]], 'right' => ['keys' => [KeyCode::RIGHT]],
  ]);
  $this->game = new PauseGameFixture();
  $this->scene = new PauseBattleFixture($this->game);
});

afterEach(function () {
  $this->scene->pauseState?->exit();
  foreach ($this->statics as $class => $values) {
    foreach ($values as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('retains real ATB startup context gauges queue and actions across repeated pauses with no opening fallthrough', function () {
  $engine = $this->game->engine;
  $before = $engine->snapshot();
  for ($repeat = 0; $repeat < 3; $repeat++) {
    pauseKey($this->scene, $this->source, KeyCode::P);
    $cameraUpdates = $this->scene->camera->updates;
    expect($this->scene->state)->toBe($this->scene->pauseState)->and(Input::isButtonDown('pause'))->toBeFalse();
    pauseKey($this->scene, $this->source, KeyCode::ENTER, 60);
    expect($engine->snapshot())->toBe($before)->and($engine->runs)->toBe(0)
      ->and($this->scene->camera->updates)->toBe($cameraUpdates);
    pauseKey($this->scene, $this->source, KeyCode::P);
    pauseKey($this->scene, $this->source, KeyCode::ENTER, 0.2);
    expect($this->scene->state)->toBe($this->scene->runState)->and($engine->starts)->toBe(1)
      ->and($engine->snapshot())->toBe($before)->and(Input::isButtonDown('confirm'))->toBeFalse();
  }
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 0.01);
  pauseKey($this->scene, $this->source);
  expect($engine->runs)->toBe(1)->and($engine->snapshot()[2] - $before[2])->toBe(0.01);
});

it('reuses Config without entering the field and returns Config focus with opening and closing input consumed', function () {
  pauseKey($this->scene, $this->source, KeyCode::P);
  pauseKey($this->scene, $this->source, null, 0.2);
  pauseKey($this->scene, $this->source, KeyCode::DOWN);
  pauseKey($this->scene, $this->source, KeyCode::ENTER);
  pauseKey($this->scene, $this->source, KeyCode::ENTER, 0.2);
  expect($this->scene->pauseState->configMenu)->not->toBeNull()->and($this->game->engine->runs)->toBe(0)
    ->and($this->game->sceneManager->currentScene)->toBe($this->scene);
  pauseKey($this->scene, $this->source, KeyCode::C);
  expect($this->scene->pauseState->configMenu)->toBeNull()->and($this->scene->pauseState->menu->selection)->toBe(1)
    ->and($this->scene->state)->toBe($this->scene->pauseState)->and(Input::isButtonDown('cancel'))->toBeFalse();
});

it('repaints resize and modal resume without resetting selection or confirmation or rebuilding the retained frame', function () {
  $this->scene->graphical = true;
  pauseKey($this->scene, $this->source, KeyCode::P);
  pauseKey($this->scene, $this->source, null, 0.2);
  pauseKey($this->scene, $this->source, KeyCode::DOWN);
  pauseKey($this->scene, $this->source, KeyCode::DOWN);
  pauseKey($this->scene, $this->source, KeyCode::ENTER);
  pauseKey($this->scene, $this->source, null, 0.08);
  pauseKey($this->scene, $this->source, null, 0.08);
  $menu = $this->scene->pauseState->menu;
  $this->scene->onScreenResize(135, 36);
  $this->scene->resume();
  $frame = $this->scene->getPresentationCanvas();
  expect($menu->selection)->toBe(0)->and($menu->heading())->toBe('Return to title?')
    ->and($this->scene->pauseState->menu)->toBe($menu)->and($this->scene->baseFrames)->toBe(1)
    ->and(array_column($frame->textLayers, 'id'))->toContain('battle-pause');
});

it('discards pending destructive completion on stop and cannot resume or execute after application stop', function () {
  pauseKey($this->scene, $this->source, KeyCode::P);
  pauseKey($this->scene, $this->source, null, 0.2);
  $menu = $this->scene->pauseState->menu;
  $menu->navigate(3); $menu->confirm();
  pauseKey($this->scene, $this->source, null, 0.08);
  pauseKey($this->scene, $this->source, null, 0.08);
  $menu->navigate(1); $menu->confirm();
  $this->scene->stop();
  $this->scene->now += 10;
  expect($menu->tick())->toBeNull()->and($this->scene->pauseState->menu)->toBeNull();
  $this->game->quit();
  $this->scene->resume(); $this->scene->resumeBattle(); $this->scene->update();
  expect($this->game->quits)->toBe(1)->and($this->game->engine->runs)->toBe(0);
});

it('omits redundant control footer text from terminal pause and confirmations', function (int $selection) {
  pauseKey($this->scene, $this->source, KeyCode::P);
  pauseKey($this->scene, $this->source, null, 0.2);
  for ($index = 0; $index < $selection; $index++) {
    pauseKey($this->scene, $this->source, KeyCode::DOWN);
  }
  if ($selection > 0) {
    pauseKey($this->scene, $this->source, KeyCode::ENTER);
    pauseKey($this->scene, $this->source, null, 0.08);
    pauseKey($this->scene, $this->source, null, 0.08);
  }
  $rows = new ReflectionProperty(Console::class, 'overlays')->getValue()['battle-pause']['rows'];
  $text = implode("\n", array_map(fn($row) => implode('', $row), $rows));
  expect($text)->not->toContain('Confirm', 'Back')
    ->and($text)->toContain(...$this->scene->pauseState->menu->labels());
})->with([0, 2, 3]);

it('uses actual horizontal confirmation input with side-by-side centered terminal choices', function () {
  pauseKey($this->scene, $this->source, KeyCode::P);
  pauseKey($this->scene, $this->source, null, 0.2);
  pauseKey($this->scene, $this->source, KeyCode::DOWN);
  pauseKey($this->scene, $this->source, KeyCode::DOWN);
  pauseKey($this->scene, $this->source, KeyCode::ENTER);
  pauseKey($this->scene, $this->source, null, 0.08);
  pauseKey($this->scene, $this->source, null, 0.08);
  $rows = new ReflectionProperty(Console::class, 'overlays')->getValue()['battle-pause']['rows'];
  $choice = array_values(array_filter(array_map(fn($row) => implode('', $row), $rows), fn($row) => str_contains($row, 'Cancel')));
  expect($choice)->toHaveCount(1)->and($choice[0])->toContain('Cancel', 'To Title');
  pauseKey($this->scene, $this->source, KeyCode::RIGHT);
  expect($this->scene->pauseState->menu->selection)->toBe(1);
  pauseKey($this->scene, $this->source, KeyCode::P);
  expect($this->scene->pauseState->menu->selection)->toBe(1)->and($this->scene->pauseState->menu->heading())->toBe('Return to title?');
  pauseKey($this->scene, $this->source, KeyCode::C);
  pauseKey($this->scene, $this->source, null, 0.08);
  pauseKey($this->scene, $this->source, null, 0.08);
  expect($this->scene->pauseState->menu->selection)->toBe(2)->and($this->scene->pauseState->menu->heading())->toBe('PAUSED');
});

it('preserves remaining alert and feedback intervals once using each timing owners clock', function () {
  $ui = $this->scene->ui;
  $field = $ui->fieldWindow;
  $feedbackNow = 100.0;
  new ReflectionProperty($field, 'feedbackTiming')->setValue($field,
    new \Ichiloto\Engine\Battle\Presentation\BattleFeedbackTiming(function () use (&$feedbackNow): float { return $feedbackNow; }));
  new ReflectionProperty($field, 'feedback')->setValue($field, [[
    'sequence' => 1, 'battler' => $this->scene->party->battlers[0], 'lines' => [], 'shownAt' => 99.5, 'durationSeconds' => 2.0,
  ]]);
  new ReflectionProperty(Time::class, 'time')->setValue(null, 10.0);
  $ui->alert('Remaining alert');
  $deadline = new ReflectionProperty($ui, 'alertHideTime')->getValue($ui);
  $ui->pauseTiming();
  new ReflectionProperty(Time::class, 'time')->setValue(null, 11.0);
  $feedbackNow = 102.0;
  $ui->pauseTiming(); // Nested/repeated suspension must not move either start marker.
  new ReflectionProperty(Time::class, 'time')->setValue(null, 20.0);
  $feedbackNow = 130.0;
  $ui->resumeTiming(); $ui->resumeTiming();
  expect(new ReflectionProperty($ui, 'alertHideTime')->getValue($ui) - 20)->toBe($deadline - 10)
    ->and($field->getFeedback()[0]['shownAt'])->toBe(129.5)
    ->and($field->getFeedback()[0]['durationSeconds'])->toBe(2.0);
  $ui->pauseTiming();
  new ReflectionProperty(Time::class, 'time')->setValue(null, 25.0);
  $feedbackNow = 134.0;
  $ui->resumeTiming();
  expect(new ReflectionProperty($ui, 'alertHideTime')->getValue($ui) - 25)->toBe($deadline - 10)
    ->and($field->getFeedback()[0]['shownAt'])->toBe(133.5);
  $ui->pauseTiming();
  $this->scene->stop();
  new ReflectionProperty(Time::class, 'time')->setValue(null, 60.0);
  $feedbackNow = 200.0;
  $ui->resumeTiming();
  expect($field->getFeedback()[0]['shownAt'])->toBe(133.5)
    ->and(new ReflectionProperty($ui, 'alertHideTime')->getValue($ui))->toBe($deadline + 15);
});

it('releases Config live cells on return resize or stop without changing the retained frame or newer scene writes', function (string $end) {
  $this->scene->graphical = true;
  Console::write('ORIGINAL', 15, 2);
  pauseKey($this->scene, $this->source, KeyCode::P);
  $retained = new ReflectionProperty(BattlePauseState::class, 'battlefield')->getValue($this->scene->pauseState);
  pauseKey($this->scene, $this->source, null, 0.2);
  pauseKey($this->scene, $this->source, KeyCode::DOWN);
  pauseKey($this->scene, $this->source, KeyCode::ENTER);
  pauseKey($this->scene, $this->source, null, 0.2);
  expect(array_column(Console::presentationSnapshot()->textLayers, 'id'))->toContain('pause-config');
  $config = $this->scene->pauseState->configMenu;
  pauseKey($this->scene, $this->source, KeyCode::DOWN);
  $setting = $config->selection->getActiveSetting();
  $this->scene->onScreenResize(135, 36);
  expect($config->selection->getActiveSetting())->toBe($setting)
    ->and(new ReflectionProperty(BattlePauseState::class, 'battlefield')->getValue($this->scene->pauseState))->toBe($retained);
  if ($end === 'return') {
    pauseKey($this->scene, $this->source, KeyCode::C);
  } elseif ($end === 'new-scene') {
    Console::write('NEW SCENE', 15, 2);
    $this->scene->stop();
  } else {
    $this->game->quit();
  }
  expect(array_column(Console::presentationSnapshot()->textLayers, 'id'))->not->toContain('pause-config');
  if ($end === 'new-scene') { expect(Console::snapshot()->rows[2])->toContain('NEW SCENE'); }
  expect($this->scene->baseFrames)->toBe(1)->and($this->game->engine->starts)->toBe(1);
})->with(['return', 'stop', 'new-scene']);

it('disposes partial entry without touching a Console layer it never acquired', function () {
  Console::withLayer('pause-config', fn() => Console::write('unrelated owner', 0, 0), 10000);
  $source = new class implements InputSourceInterface {
    public function poll(): ?KeyCode { return null; }
    public function reset(bool $drainBufferedInput = false): void { if ($drainBufferedInput) { throw new RuntimeException('partial entry'); } }
  };
  InputManager::setInputSource($source);
  expect(fn() => $this->scene->pauseBattle())->toThrow(RuntimeException::class, 'partial entry');
  $pause = $this->scene->pauseState;
  expect($pause->hasOwnedResources())->toBeTrue();
  $menu = $pause->menu;
  $this->scene->stop();
  expect($pause->hasOwnedResources())->toBeFalse()->and($menu->isClosed())->toBeTrue()
    ->and(Console::snapshot()->rows[0])->toStartWith('unrelated owner');
});

it('makes only owned Config and unskinned Pause cells opaque over the retained battlefield', function () {
  $this->scene->graphical = true;
  $field = $this->scene->graphicalFrame = new PresentationCanvas(1350, 720, [
    new CanvasImage('arena', 'test-sprite.png', new CanvasRectangle(0, 0, 1350, 720)),
    new CanvasImage('hud', 'test-sprite.png', new CanvasRectangle(0, 600, 1350, 120), 100),
  ], textLayers: [new CanvasTextLayer('retained-hud', 101, 0, 600, new RendererGridConfig(135, 6, 10, 20),
    [new PresentationTextRun(0, 0, 'Retained HUD', PresentationColor::rgb(255, 255, 255))])]);
  $before = $field->toArray();
  Console::write('WORLD MUST NOT BE PROJECTED', 0, 0);
  pauseKey($this->scene, $this->source, KeyCode::P);

  $assertCoverage = function (string $id, array $regions) use ($field, $before): void {
    $console = Console::presentationSnapshot();
    $source = array_column($console->textLayers, null, 'id')[$id];
    $frame = $this->scene->getPresentationCanvas();
    $layer = array_column($frame->textLayers, null, 'id')[$id];
    $covered = $expected = [];
    $colored = $blank = false;
    foreach ($source->runs as $index => $run) {
      $projected = $layer->runs[$index];
      expect([$projected->row, $projected->column, $projected->text, $projected->foreground?->toArray()])
        ->toBe([$run->row, $run->column, $run->text, $run->foreground?->toArray()])
        ->and($projected->background)->toEqual($run->background ?? PresentationColor::rgb(15, 23, 30));
      $colored = $colored || $run->foreground !== null;
      $blank = $blank || str_contains($run->text, '  ');
      for ($column = $run->column; $column < $run->column + mb_strlen($run->text, 'UTF-8'); $column++) {
        $covered[$run->row . ':' . $column] = true;
      }
    }
    foreach ($regions as [$x, $y, $width, $height]) {
      for ($row = $y; $row < $y + $height; $row++) {
        for ($column = $x; $column < $x + $width; $column++) { $expected[$row . ':' . $column] = true; }
      }
    }
    ksort($covered); ksort($expected);
    expect($covered)->toBe($expected)
      ->and($frame->images)->toBe($field->images)->and($frame->indicators)->toBe($field->indicators)
      ->and($frame->textLayers)->toHaveCount(count($field->textLayers) + 1)
      ->and($frame->textLayers[0])->toBe($field->textLayers[0])
      ->and($field->toArray())->toBe($before)
      ->and(Console::presentationSnapshot())->toEqual($console);
    if ($id === 'pause-config') { expect($colored)->toBeTrue()->and($blank)->toBeTrue(); }
  };
  $overlay = new ReflectionProperty(Console::class, 'overlays')->getValue()['battle-pause'];
  $rows = array_keys($overlay['rows']);
  $columns = array_keys($overlay['rows'][$rows[0]]);
  $assertCoverage('battle-pause', [[min($columns), min($rows), count($columns), count($rows)]]);

  pauseKey($this->scene, $this->source, null, 0.2);
  pauseKey($this->scene, $this->source, KeyCode::DOWN);
  pauseKey($this->scene, $this->source, KeyCode::ENTER);
  pauseKey($this->scene, $this->source, null, 0.2);
  $config = $this->scene->pauseState->configMenu;
  $regions = static fn(): array => array_map(static fn($window): array => [
    (int)$window->getPosition()->x, (int)$window->getPosition()->y, $window->getWidth(), $window->getHeight(),
  ], [$config->selection, $config->detail]);
  $assertCoverage('pause-config', $regions());
  pauseKey($this->scene, $this->source, KeyCode::DOWN);
  $assertCoverage('pause-config', $regions());
  $setting = $config->selection->getActiveSetting();
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 150, 'height' => 40]));
  Console::syncDimensions(150, 40);
  $this->scene->onScreenResize(150, 40);
  $assertCoverage('pause-config', $regions());
  expect($config->selection->getActiveSetting())->toBe($setting);
  pauseKey($this->scene, $this->source, KeyCode::C);
  expect(array_column($this->scene->getPresentationCanvas()->textLayers, 'id'))->not->toContain('pause-config');
  $this->scene->stop();
  expect($this->scene->getPresentationCanvas())->toBeNull()->and($field->toArray())->toBe($before);
});

it('uses shared menu-only Config over the frozen battle with honest optional fallback and original back focus', function (string $themeState) {
  $root = sys_get_temp_dir() . '/ichiloto-pause-config-' . bin2hex(random_bytes(5));
  mkdir($root . '/Data/Presentation', 0777, true);
  $debug = new ReflectionClass(Debug::class)->getStaticProperties();
  Debug::configure(['log_directory' => $root . '/logs']);
  $caps = $themeState === 'unsupported' ? ['graphical_canvas'] : MenuPresentationCatalog::CAPABILITIES;
  $transport = new FakeRendererTransport();
  $transport->batches = [[RendererEvent::fromJson(json_encode(['type' => 'ready', 'protocol' => 2, 'capabilities' => $caps]))]];
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), $root,
    requiredCapabilities: $caps), $transport);
  try {
    if ($themeState !== 'absent') {
      $data = ['schema' => 'ichiloto.menu/1', 'showInputHints' => false];
      if ($themeState === 'invalid') { $data['frames']['slider.track'] = ['asset' => 'missing.png']; }
      file_put_contents($root . '/' . MenuPresentationCatalog::FILE, '<?php return ' . var_export($data, true) . ';');
    }
    $runtime->start('Config fixture', 135, 36);
    $this->game->useRendererRuntime($runtime);
    InputManager::setInputSource($this->source);
    $field = $this->scene->graphicalFrame = new PresentationCanvas(1350, 720, textLayers: [
      new CanvasTextLayer('retained-field', 50, 0, 0, new RendererGridConfig(10, 1, 10, 20),
        [new PresentationTextRun(0, 0, 'Battle', PresentationColor::rgb(250, 250, 250))]),
    ]);
    $this->scene->graphical = true;
    $engine = $this->game->engine->snapshot();
    pauseKey($this->scene, $this->source, KeyCode::P);
    pauseKey($this->scene, $this->source, null, 0.2);
    pauseKey($this->scene, $this->source, KeyCode::DOWN);
    pauseKey($this->scene, $this->source, KeyCode::ENTER);
    pauseKey($this->scene, $this->source, null, 0.2);
    $config = $this->scene->pauseState->configMenu;
    expect($config)->not->toBeNull()->and($this->scene->pauseSkin)->toBeNull();
    $frame = $this->scene->getPresentationCanvas();
    $this->scene->getPresentationCanvas();
    $layers = array_column($frame->textLayers, null, 'id');
    expect($layers['retained-field'])->toBe($field->textLayers[0]);
    if ($themeState === 'valid') {
      expect($layers)->toHaveKey('config-title')->not->toHaveKey('pause-config')->not->toHaveKey('menu-background');
      $backing = $layers['config-backing']->clipRect;
      expect($backing->x)->toBeGreaterThan(0)->and($backing->y)->toBeGreaterThan(0)
        ->and($layers['config-backing']->layer)->toBeGreaterThan($layers['retained-field']->layer);
      $runtime->present($this->scene);
      $wire = $transport->sent[array_key_last($transport->sent)]->payload;
      expect(array_column($wire['canvas']['textLayers'], 'id'))->toContain('config-title', 'retained-field')
        ->and(is_file($root . '/logs/error.log'))->toBeFalse();
    } else {
      expect($layers)->toHaveKey('pause-config')->not->toHaveKey('config-title');
      $text = implode('', array_column($layers['pause-config']->runs, 'text'));
      expect($text)->toContain('Volume', '75%', 'Description', 'Sets the master volume');
      if ($themeState !== 'absent') {
        $log = file_get_contents($root . '/logs/error.log');
        expect(substr_count($log, 'Config presentation degraded to terminal'))->toBe(1);
      } else { expect(is_file($root . '/logs/error.log'))->toBeFalse(); }
    }
    pauseKey($this->scene, $this->source, KeyCode::DOWN);
    $this->scene->onScreenResize(135, 36);
    expect($config->selection->getActiveIndex())->toBe(1)
      ->and($this->game->engine->snapshot())->toBe($engine)->and($this->game->engine->runs)->toBe(0);
    pauseKey($this->scene, $this->source, KeyCode::C);
    expect($this->scene->pauseState->configMenu)->toBeNull()->and($this->scene->pauseState->menu->selection)->toBe(1)
      ->and(Input::isButtonDown('cancel'))->toBeFalse()->and($this->scene->baseFrames)->toBe(1);
    expect(array_column($this->scene->getPresentationCanvas()->textLayers, 'id'))->not->toContain('config-title', 'pause-config');
  } finally {
    $runtime->shutdown();
    foreach ($debug as $name => $value) { new ReflectionProperty(Debug::class, $name)->setValue(null, $value); }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
  }
})->with(['valid', 'absent', 'unsupported', 'invalid']);
