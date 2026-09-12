<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\FieldMusicCatalog;
use Ichiloto\Engine\Audio\CinematicMusicRequest;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\IO\InputSources\TerminalInputSource;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Runtime\RendererWindowClosed;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Rendering\Sprites\DirectionalGraphicalSpriteSet;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\AppConfig;
use function Tests\Support\Rendering\graphicalSpriteData;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

/** Real Game loop/quit/input policy without project bootstrap or a physical terminal. */
final class RendererRuntimeGameProbe extends Game
{
  public int $updates = 0;
  public ?Closure $onUpdate = null;
  public function __construct()
  {
    $this->name = 'Runtime test';
    $this->width = 12;
    $this->height = 4;
    $this->options = ['fps' => 1000];
    $this->observers = new ItemList(ObserverInterface::class);
    $this->staticObservers = new ItemList(StaticObserverInterface::class);
  }
  public function __destruct() {}
  protected function start(): void { $this->startInputSession(); $this->isRunning = true; }
  protected function update(): void { $this->updates++; ($this->onUpdate)?->__invoke(); $this->quit(); }
  public function startInput(): void { $this->startInputSession(); }
  public function resize(): void { $this->syncScreenSize(); }
  public function renderFrame(): void { $this->render(); }
}

final class RendererRuntimeNotifications extends NotificationManager
{
  public function __construct() {}
  public function __destruct() {}
  public function update(): void {}
  public function render(?int $x = null, ?int $y = null): void {}
}

beforeEach(function () {
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  $this->inputState = new ReflectionClass(InputManager::class)->getStaticProperties();
  $this->configState = new ReflectionClass(ConfigStore::class)->getStaticProperties();
  $this->timerState = new ReflectionClass(Timers::class)->getStaticProperties();
  Timers::clear();
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::syncDimensions(12, 4);
  InputManager::setInputSource(new TerminalInputSource());
  $this->previous = InputManager::getInputSource();
  $this->transport = new FakeRendererTransport();
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), __DIR__, protocol: RendererProtocolVersion::V1), $this->transport);
  ob_start();
});

afterEach(function () {
  try { $this->runtime->shutdown(); } catch (Throwable) {}
  ob_end_clean();
  foreach ([Console::class => $this->consoleState, InputManager::class => $this->inputState,
    ConfigStore::class => $this->configState, Timers::class => $this->timerState] as $class => $state) {
    foreach ($state as $name => $value) {
      new ReflectionProperty($class, $name)->setValue(null, $value);
    }
  }
});

it('opts in exactly once and shares input and presentation on one session', function () {
  expect(InputManager::requiresTerminalInput())->toBeTrue()->and($this->transport->session)->toBeNull();
  $this->runtime->start('Test', 12, 4);
  expect(InputManager::getInputSource())->toBeInstanceOf(RendererInputSource::class)
    ->and(InputManager::requiresTerminalInput())->toBeFalse()
    ->and($this->transport->session->grid->columns)->toBe(12);
  Console::write('TITLE', 0, 0);
  expect($this->runtime->present(null))->toBeTrue()->and($this->runtime->present(null))->toBeFalse();
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":1,"type":"key","key":"up"}')];
  expect(InputManager::getInputSource()->poll())->toBe(KeyCode::UP)
    ->and($this->transport->sent)->toHaveCount(1)->and($this->transport->sent[0]->payload['text'][0])->toBe('TITLE       ');
  expect(fn() => $this->runtime->start('Test', 12, 4))->toThrow(LogicException::class);
  $this->runtime->shutdown();
  $this->runtime->shutdown();
  expect(InputManager::getInputSource())->toBe($this->previous)->and($this->transport->shutdowns)->toBe(1);
});

it('selects renderer-only game output while preserving frames and silent cleanup', function () {
  $game = new RendererRuntimeGameProbe();
  $game->useRendererRuntime($this->runtime);
  $game->startInput();
  Console::clear();
  Console::enterAlternateScreen();
  Console::cursor()->hide();
  Console::write('GRAPHICAL', 0, 0);
  $this->runtime->present(null);
  $game->quit();
  expect(Console::isTerminalOutputEnabled())->toBeFalse()
    ->and($this->transport->sent[0]->payload['text'][0])->toBe('GRAPHICAL   ')
    ->and(ob_get_contents())->toBe('');
});

it('runs a real PHP-only peer through explicit v1 and v2 runtime lifecycles', function ($protocol) {
  $capture = tempnam(sys_get_temp_dir(), 'runtime-version-');
  $process = new RendererProcessConfig([PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', 'ready_key', $capture]);
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig($process, __DIR__, protocol: $protocol), new ProcessRendererTransport($process));
  try {
    $this->runtime->start('Versioned runtime', 12, 4);
    InputManager::handleInput();
    expect(InputManager::getPressedKeyCode())->toBe(KeyCode::W);
    Console::write('TITLE', 0, 0);
    $this->runtime->present(null);
    expect($this->runtime->shutdown())->toBe(0);
    $messages = array_map(fn($line) => json_decode($line, true), file($capture, FILE_IGNORE_NEW_LINES));
    expect(array_column($messages, 'protocol'))->toBe(array_fill(0, 3, $protocol->value))
      ->and(array_column($messages, 'type'))->toBe(['hello', 'frame', 'shutdown']);
    if ($protocol === RendererProtocolVersion::V1) {
      expect($messages[1]['text'][0])->toBe('TITLE       ')->and($messages[1])->not->toHaveKey('textLayers');
    } else {
      expect($messages[1]['textLayers'][0]['runs'][0]['text'])->toBe('TITLE       ')->and($messages[1])->not->toHaveKey('text');
    }
  } finally { unlink($capture); }
})->with([RendererProtocolVersion::V1, RendererProtocolVersion::V2]);

it('preserves input and shuts down once when startup fails after a child was started', function () {
  $this->transport->failure = new RendererTransportException('handshake fixture failure');
  expect(fn() => $this->runtime->start('Test', 12, 4))->toThrow(RendererTransportException::class, 'handshake fixture failure');
  expect(InputManager::getInputSource())->toBe($this->previous)->and($this->transport->shutdowns)->toBe(1)
    ->and($this->transport->running)->toBeFalse();
});

it('keeps game startup failure and cleanup from borrowing or painting the terminal', function () {
  $this->transport->failure = new RendererTransportException('handshake fixture failure');
  $game = new RendererRuntimeGameProbe();
  $game->useRendererRuntime($this->runtime);
  expect(fn() => $game->startInput())->toThrow(RendererTransportException::class);
  $game->quit();
  expect(Console::isTerminalOutputEnabled())->toBeFalse()
    ->and($this->transport->shutdowns)->toBe(1)
    ->and(InputManager::getInputSource())->toBe($this->previous)
    ->and(ob_get_contents())->toBe('');
});

it('services changed frame bytes before returning to the game frame sleep', function () {
  $process = new RendererProcessConfig([PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', 'ready_key']);
  $transport = new ProcessRendererTransport($process);
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig($process, __DIR__), $transport);
  $this->runtime->start('Presentation boundary', 12, 4);
  Console::write('VISIBLE NOW', 0, 0);
  expect($this->runtime->present(null))->toBeTrue();
  // This small frame fits one writable-pipe budget. No next-frame poll or shutdown
  // may be required to begin delivering a frame that PHP has already finished.
  expect($transport->getPendingWriteBytes())->toBe(0);
});

it('keeps presentation delivery bounded when a complete frame exceeds the I/O budget', function () {
  $process = new RendererProcessConfig([PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', 'ready_key'], ioBudgetBytes: 16);
  $transport = new ProcessRendererTransport($process);
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig($process, __DIR__), $transport);
  $this->runtime->start('Bounded delivery', 12, 4);
  Console::write('VISIBLE NOW', 0, 0);
  expect($this->runtime->present(null))->toBeTrue()
    ->and($transport->getPendingWriteBytes())->toBeGreaterThan(0);
  $pending = $transport->getPendingWriteBytes();
  $this->runtime->pump();
  expect($transport->getPendingWriteBytes())->toBe($pending - 16);
});

it('does not poll the transport twice just to drain already pumped lifecycle events', function () {
  $this->runtime->start('Poll budget', 12, 4);
  $before = $this->transport->polls;
  $this->runtime->pump();
  expect($this->transport->polls - $before)->toBe(1);
  Console::write('TITLE', 0, 0);
  $before = $this->transport->polls;
  $this->runtime->present(null);
  expect($this->transport->polls - $before)->toBe(2); // ingress and changed-frame delivery
  $before = $this->transport->polls;
  $this->runtime->present(null);
  expect($this->transport->polls - $before)->toBe(1); // no second delivery/snapshot pass
});

it('propagates transport failures and diagnostic-bearing renderer errors', function ($json) {
  $this->runtime->start('Test', 12, 4);
  if ($json !== null) {
    $this->transport->batches[] = [RendererEvent::fromJson($json)];
  } else {
    $this->transport->failure = new RendererTransportException('broken transport');
  }
  expect(fn() => $this->runtime->pump())->toThrow(RendererTransportException::class, $json === null ? 'broken transport' : 'missing image');
})->with([null, '{"protocol":1,"type":"error","message":"missing image"}']);

it('keeps native close outside input and unwinds waits persistently', function () {
  $this->runtime->start('Test', 12, 4);
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":1,"type":"close_requested"}')];
  expect(fn() => $this->runtime->pump())->toThrow(RendererWindowClosed::class);
  expect(fn() => $this->runtime->pump())->toThrow(RendererWindowClosed::class);
  expect(InputManager::getInputSource()->poll())->toBeNull();
});

it('lets real Game quit on native close without updating gameplay or confirming', function () {
  $game = new RendererRuntimeGameProbe();
  $game->useRendererRuntime($this->runtime);
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":1,"type":"ready"}')];
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":1,"type":"close_requested"}')];
  $game->run();
  expect($game->updates)->toBe(0)->and($this->transport->shutdowns)->toBe(1)
    ->and(InputManager::getInputSource())->toBe($this->previous)
    ->and(new ReflectionProperty(Game::class, 'terminalInputConfigured')->getValue($game))->toBeFalse();
});

it('keeps the Game grid fixed and restores resources on ordinary quit without touching stdin modes', function () {
  $game = new RendererRuntimeGameProbe();
  $game->useRendererRuntime($this->runtime);
  $before = stream_get_meta_data(STDIN)['blocked'];
  $game->startInput();
  $game->resize();
  expect($this->transport->session->grid->toArray())->toBe(['columns' => 12, 'rows' => 4, 'cellWidth' => 16, 'cellHeight' => 24])
    ->and([Console::getWidth(), Console::getHeight()])->toBe([12, 4])
    ->and(stream_get_meta_data(STDIN)['blocked'])->toBe($before);
  $game->quit();
  expect($this->transport->shutdowns)->toBe(1)->and(InputManager::getInputSource())->toBe($this->previous)
    ->and(stream_get_meta_data(STDIN)['blocked'])->toBe($before);
});

it('does not start a renderer for terminal-only Game and rejects late or repeated attachment', function () {
  $game = new RendererRuntimeGameProbe();
  expect(new ReflectionProperty(Game::class, 'rendererRuntime')->getValue($game))->toBeNull()
    ->and($this->transport->session)->toBeNull()->and(InputManager::requiresTerminalInput())->toBeTrue();
  $game->useRendererRuntime($this->runtime);
  expect(fn() => $game->useRendererRuntime($this->runtime))->toThrow(LogicException::class);
});

it('rejects fixed-grid mismatch and never captures unfinished composition', function () {
  $this->runtime->start('Test', 12, 4);
  Console::beginFrame();
  expect(fn() => $this->runtime->present(null))->toThrow(RuntimeException::class, 'active');
  Console::endFrame();
  Console::syncDimensions(13, 4);
  expect(fn() => $this->runtime->present(null))->toThrow(InvalidArgumentException::class, 'fixed renderer session grid');
});

it('honors explicit grid dimensions even when they equal the legacy terminal auto defaults', function ($options) {
  $game = new RendererRuntimeGameProbe();
  $size = new ReflectionMethod(Game::class, 'resolveScreenSize')->invoke($game, $options);
  expect($size)->toBe(['width' => DEFAULT_SCREEN_WIDTH, 'height' => DEFAULT_SCREEN_HEIGHT]);
})->with([
  [['width' => DEFAULT_SCREEN_WIDTH, 'height' => DEFAULT_SCREEN_HEIGHT]],
  [['screen' => ['width' => DEFAULT_SCREEN_WIDTH, 'height' => DEFAULT_SCREEN_HEIGHT]]],
]);

it('retains legacy terminal auto sizing when dimensions were not explicitly requested', function () {
  $game = new RendererRuntimeGameProbe();
  new ReflectionProperty(Game::class, 'width')->setValue($game, DEFAULT_SCREEN_WIDTH);
  new ReflectionProperty(Game::class, 'height')->setValue($game, DEFAULT_SCREEN_HEIGHT);
  $available = Console::getAvailableSize();
  expect(new ReflectionMethod(Game::class, 'resolveScreenSize')->invoke($game, []))->toBe($available);
});

it('presents the same field ownership from real Game renders and blocked ticks without capturing partial frames', function () {
  $game = new RendererRuntimeGameProbe();
  $scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()->onlyMethods(['render', 'getUI'])->getMock();
  $scene->method('getUI')->willReturn($this->createStub(UIManager::class));
  $sceneManager = makeBareScene(SceneManager::class);
  $sceneManager->currentScene = $scene;
  new ReflectionProperty(Game::class, 'sceneManager')->setValue($game, $sceneManager);
  new ReflectionProperty(Game::class, 'notificationManager')->setValue($game, new RendererRuntimeNotifications());
  new ReflectionProperty(Game::class, 'audioManager')->setValue($game, $this->createStub(AudioManager::class));
  ConfigStore::put(AppConfig::class, new SceneAudioConfigStub());
  $camera = new Camera($scene, 12, 4);
  new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
  $player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()->onlyMethods(['getGraphicalSpriteDefinition', 'getGraphicalSpriteWorldPosition'])->getMock();
  $player->method('getGraphicalSpriteDefinition')->willReturn(DirectionalGraphicalSpriteSet::fromArray(graphicalSpriteData())->south);
  $player->method('getGraphicalSpriteWorldPosition')->willReturn(new Vector2(2, 1));
  new ReflectionProperty(Player::class, 'isActive')->setValue($player, true);
  new ReflectionProperty(GameScene::class, 'player')->setValue($scene, $player);
  $field = makeBareScene(FieldState::class);
  new ReflectionProperty(GameScene::class, 'fieldState')->setValue($scene, $field);
  new ReflectionProperty(GameScene::class, 'state')->setValue($scene, $field);
  $game->useRendererRuntime($this->runtime);
  $game->startInput();
  Console::write('.', 2, 1);
  Console::withLayer('player', fn() => Console::write('v', 2, 1));
  $game->renderFrame();
  Console::write('Dialogue', 0, 3);
  $game->tickWhileBlocked();
  expect($this->transport->sent)->toHaveCount(2)
    ->and($this->transport->sent[1]->payload['sprites'])->toBe($this->transport->sent[0]->payload['sprites'])
    ->and($this->transport->sent[1]->payload['text'][1][2])->toBe('.')
    ->and(Console::charAt(2, 1))->toBe('v');
  Console::beginFrame();
  $game->tickWhileBlocked();
  Console::endFrame();
  expect($this->transport->sent)->toHaveCount(2);
  $sceneManager->currentScene = null;
  $game->tickWhileBlocked();
  expect($this->transport->sent[2]->payload['sprites'])->toBe([]);
  $game->quit();
});

it('keeps scenario and temporary music ownership identical during terminal and renderer waits', function (bool $graphical) {
  $game = new RendererRuntimeGameProbe();
  [$scene, $map, , $manager] = makeFieldAudioScene();
  $audio = new RecordingAudioManager($game);
  new ReflectionProperty(SceneManager::class, 'game')->setValue($manager, $game);
  new ReflectionProperty(Game::class, 'sceneManager')->setValue($game, $manager);
  new ReflectionProperty(Game::class, 'audioManager')->setValue($game, $audio);
  new ReflectionProperty(Game::class, 'notificationManager')->setValue($game, new RendererRuntimeNotifications());
  new ReflectionProperty(GameScene::class, 'state')->setValue($scene, makeBareScene(FieldState::class));
  ConfigStore::put(FieldMusicCatalog::class, new FieldMusicCatalog([[
    'id' => 'mission', 'track' => 'mission-theme',
    'conditions' => [['type' => 'switch', 'name' => 'on_mission']],
  ]]));
  if ($graphical) { $game->useRendererRuntime($this->runtime); $game->startInput(); }

  $enter = function (string $track) use ($scene, $map): void {
    new ReflectionMethod($map, 'applyMapBackgroundMusic')->invoke($map, $track, []);
    $scene->refreshFieldMusic();
  };
  $scene->gameState->setSwitch('on_mission', true);
  $enter('outside');
  $game->tickWhileBlocked();
  $enter('interior');
  expect($audio->currentBackgroundMusic)->toBe('mission-theme');

  $scene->holdFieldMusic();
  try {
    $audio->playBackgroundMusic('rest');
    $scene->refreshFieldMusic(force: true);
    $game->tickWhileBlocked();
    expect($audio->currentBackgroundMusic)->toBe('rest');
  } finally { $scene->releaseFieldMusic(); }
  expect($audio->currentBackgroundMusic)->toBe('mission-theme');

  $audio->beginCinematicMusic(new CinematicMusicRequest('cinematic'));
  $enter('return-map');
  $game->tickWhileBlocked();
  expect($audio->currentBackgroundMusic)->toBe('cinematic');
  $audio->finalizeCinematicMusic();
  $game->tickWhileBlocked();
  $scene->restoreBackgroundMusic();
  expect($audio->currentBackgroundMusic)->toBe('mission-theme');
  $scene->gameState->setSwitch('on_mission', false);
  $scene->refreshFieldMusic();
  expect($audio->currentBackgroundMusic)->toBe('return-map');

  $beforeQuit = count($audio->calls);
  $game->quit();
  $game->quit();
  expect($audio->currentBackgroundMusic)->toBeNull()
    ->and(array_slice($audio->calls, $beforeQuit))->toBe([['stopBackgroundMusic', null]])
    ->and(InputManager::getInputSource())->toBe($this->previous)
    ->and($this->transport->shutdowns)->toBe($graphical ? 1 : 0);
})->with(['terminal' => [false], 'renderer' => [true]]);

it('cleans up scenario audio exactly once when native close interrupts a held field cue', function () {
  $game = new RendererRuntimeGameProbe();
  [$scene, , , $manager] = makeFieldAudioScene();
  $audio = new RecordingAudioManager($game);
  new ReflectionProperty(SceneManager::class, 'game')->setValue($manager, $game);
  new ReflectionProperty(Game::class, 'audioManager')->setValue($game, $audio);
  ConfigStore::put(FieldMusicCatalog::class, new FieldMusicCatalog([[
    'id' => 'mission', 'track' => 'mission-theme', 'conditions' => [],
  ]]));
  $scene->refreshFieldMusic();
  $transport = $this->transport;
  $game->onUpdate = function () use ($game, $scene, $audio, $transport): void {
    $scene->holdFieldMusic();
    try {
      $audio->playBackgroundMusic('rest');
      $scene->refreshFieldMusic(force: true);
      // The normal wait callback propagates close through the action's
      // finally block before Game::run catches it and performs cleanup.
      $transport->batches[] = [RendererEvent::fromJson('{"protocol":1,"type":"close_requested"}')];
      $game->tickWhileBlocked();
      throw new LogicException('Expected the pending native close.');
    } finally { $scene->releaseFieldMusic(); }
  };
  $game->useRendererRuntime($this->runtime);
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":1,"type":"ready"}')];
  $beforeRun = count($audio->calls);
  $game->run();
  expect($game->updates)->toBe(1)->and($audio->currentBackgroundMusic)->toBeNull()
    ->and(array_slice($audio->calls, $beforeRun))->toBe([
      ['playBackgroundMusic', 'rest'], ['playBackgroundMusic', 'mission-theme'], ['stopBackgroundMusic', null],
    ])
    ->and($this->transport->shutdowns)->toBe(1)
    ->and(InputManager::getInputSource())->toBe($this->previous);
});
