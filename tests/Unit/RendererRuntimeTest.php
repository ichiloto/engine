<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\CinematicMusicRequest;
use Ichiloto\Engine\Audio\FieldMusicCatalog;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\IO\InputSources\TerminalInputSource;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Runtime\RendererWindowClosed;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\AppConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;
use function Tests\Support\Rendering\characterSheetData;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

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
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), __DIR__), $this->transport);
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
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"key","key":"up"}')];
  expect(InputManager::getInputSource()->poll())->toBe(KeyCode::UP)
    ->and($this->transport->sent)->toHaveCount(1)
    ->and(RetainedFrameState::replay($this->transport->sent)[0]['textLayers'][0]['runs'][0]['text'])->toBe('TITLE       ')
    ->and($this->transport->sent[0]->payload['reset'])->toBeTrue();
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
    ->and(RetainedFrameState::replay($this->transport->sent)[0]['textLayers'][0]['runs'][0]['text'])->toBe('GRAPHICAL')
    ->and(ob_get_contents())->toBe('');
});

it('updates native activation state without consuming semantic input', function () {
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), __DIR__,
    protocol: RendererProtocolVersion::V2, requiredCapabilities: ['window_activation']), $this->transport);
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["window_activation"]}'),
    RendererEvent::fromJson('{"protocol":2,"type":"window_activation","active":false}')];
  $this->runtime->start('Focus', 12, 4);
  expect($this->runtime->windowActive)->toBeFalse();
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"window_activation","active":true}'),
    RendererEvent::fromJson('{"protocol":2,"type":"key","key":"up"}')];
  $this->runtime->pump();
  expect($this->runtime->windowActive)->toBeTrue()->and(InputManager::getInputSource()->poll())->toBe(KeyCode::UP);
});

it('runs a real PHP-only peer through the protocol two retained runtime lifecycle', function () {
  $capture = tempnam(sys_get_temp_dir(), 'runtime-version-');
  $process = new RendererProcessConfig([PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', 'ready_key', $capture]);
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig($process, __DIR__), new ProcessRendererTransport($process));
  try {
    $this->runtime->start('Versioned runtime', 12, 4);
    InputManager::handleInput();
    expect(InputManager::getPressedKeyCode())->toBe(KeyCode::W);
    Console::write('TITLE', 0, 0);
    $this->runtime->present(null);
    expect($this->runtime->shutdown())->toBe(0);
    $messages = array_map(fn($line) => json_decode($line, true), file($capture, FILE_IGNORE_NEW_LINES));
    expect(array_column($messages, 'protocol'))->toBe([2, 2, 2])
      ->and(array_column($messages, 'type'))->toBe(['hello', 'frame', 'shutdown']);
    expect($messages[1]['reset'])->toBeTrue()->and($messages[1]['operations'][0]['op'])->toBe('put')
      ->and($messages[1]['operations'][0]['value']['runs'][0]['text'])->toBe('TITLE       ')
      ->and($messages[1])->not->toHaveKeys(['text', 'textLayers', 'sprites', 'tileBatches']);
  } finally { unlink($capture); }
});

it('removes protocol one native runtime selection before acquiring any session or input ownership', function () {
  expect(fn() => new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), __DIR__, protocol: RendererProtocolVersion::V1))
    ->toThrow(InvalidArgumentException::class, 'protocol 1 full-frame path was removed');
  expect($this->transport->session)->toBeNull()->and(InputManager::getInputSource())->toBe($this->previous);
});

it('uploads initial retained state once and does not rebuild it for staged or presented acknowledgements', function () {
  $this->runtime->start('Retained startup', 12, 4);
  Console::write('READY', 0, 0);
  expect($this->runtime->present(null))->toBeTrue();
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"frame_ack","generation":1,"frame":1,"presented":false}'),
    RendererEvent::fromJson('{"protocol":2,"type":"frame_ack","generation":1,"frame":1,"presented":true}')];
  expect($this->runtime->present(null))->toBeFalse()->and($this->runtime->present(null))->toBeFalse()
    ->and($this->transport->sent)->toHaveCount(1)->and($this->transport->sent[0]->payload['reset'])->toBeTrue()
    ->and($this->transport->sent[0]->payload['generation'])->toBe(1);
});

it('invalidates once for a drained resize or rejection batch and resends complete retained state without losing keys', function (bool $rejected) {
  $debug = new ReflectionClass(Debug::class)->getStaticProperties();
  $directory = sys_get_temp_dir() . '/retained-runtime-' . bin2hex(random_bytes(6));
  Debug::configure(['log_directory' => $directory]);
  try {
    $this->runtime->start('Resynchronize', 12, 4);
    Console::write('UNCHANGED', 0, 0);
    $this->runtime->present(null);
    $baseline = RetainedFrameState::replay($this->transport->sent)[0];
    $batch = [RendererEvent::fromJson('{"protocol":2,"type":"resized"}'), RendererEvent::fromJson('{"protocol":2,"type":"resized"}')];
    if ($rejected) {
      $batch[] = RendererEvent::fromJson('{"protocol":2,"type":"frame_rejected","generation":1,"expectedGeneration":0,"message":"invalid retained delta","resyncRequired":true}');
    }
    $batch[] = RendererEvent::fromJson('{"protocol":2,"type":"key","key":"up"}');
    $this->transport->batches[] = $batch;
    $this->runtime->pump();
    expect(InputManager::getInputSource()->poll())->toBe(KeyCode::UP)
      ->and($this->runtime->present(null))->toBeTrue()->and($this->runtime->present(null))->toBeFalse()
      ->and($this->transport->sent)->toHaveCount(2)
      ->and($this->transport->sent[1]->payload['reset'])->toBeTrue()
      ->and($this->transport->sent[1]->payload['baseGeneration'])->toBe(1)
      ->and($this->transport->sent[1]->payload['generation'])->toBe(2);
    // A reset also reconstructs a peer that missed the original transaction.
    $recovered = RetainedFrameState::replay([$this->transport->sent[1]])[0];
    unset($baseline['frame'], $recovered['frame']);
    expect($recovered)->toBe($baseline)->and($this->transport->running)->toBeTrue();
    if ($rejected) {
      expect(file_get_contents($directory . '/warning.log'))->toContain('invalid retained delta', 'resynchronized')
        ->and(substr_count(file_get_contents($directory . '/warning.log'), 'invalid retained delta'))->toBe(1);
    } else {
      expect(file_exists($directory . '/warning.log'))->toBeFalse();
    }
  } finally {
    foreach ($debug as $property => $value) { new ReflectionProperty(Debug::class, $property)->setValue(null, $value); }
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    if (is_dir($directory)) { rmdir($directory); }
  }
})->with(['resize only' => [false], 'resize plus rejected update' => [true]]);

it('continues real gameplay and semantic input after a rejected update instead of stopping the game', function () {
  $game = new RendererRuntimeGameProbe();
  $game->useRendererRuntime($this->runtime);
  $continued = false;
  $game->onUpdate = function () use (&$continued): void {
    Console::write('PLAY', 0, 0);
    $this->runtime->present(null);
    $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"frame_rejected","generation":1,"expectedGeneration":0,"message":"invalid update fixture","resyncRequired":true}'),
      RendererEvent::fromJson('{"protocol":2,"type":"key","key":"right"}')];
    $this->runtime->pump();
    expect(InputManager::getInputSource()->poll())->toBe(KeyCode::RIGHT);
    Console::write('CONTINUED', 0, 1);
    expect($this->runtime->present(null))->toBeTrue();
    $continued = true;
  };
  $game->run();
  expect($continued)->toBeTrue()->and($game->updates)->toBe(1)
    ->and($this->transport->shutdowns)->toBe(1)->and(InputManager::getInputSource())->toBe($this->previous);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect(array_column(end($frames)['textLayers'][0]['runs'], 'text'))->toContain('CONTINUED');
});

it('recovers once above the highest expected receiver generation in a mixed rejection drain', function () {
  $this->runtime->start('Ahead receiver', 12, 4);
  Console::write('PRESERVED', 0, 0);
  $this->runtime->present(null);
  $this->transport->batches[] = [
    RendererEvent::fromJson('{"protocol":2,"type":"frame_rejected","generation":1,"expectedGeneration":99,"message":"ahead","resyncRequired":true}'),
    RendererEvent::fromJson('{"protocol":2,"type":"resized"}'),
    RendererEvent::fromJson('{"protocol":2,"type":"frame_rejected","generation":1,"expectedGeneration":12,"message":"older","resyncRequired":true}'),
    RendererEvent::fromJson('{"protocol":2,"type":"key","key":"up"}'),
  ];
  expect($this->runtime->present(null))->toBeTrue()->and($this->transport->sent)->toHaveCount(2)
    ->and($this->transport->sent[1]->payload['generation'])->toBe(100)
    ->and($this->transport->sent[1]->payload['baseGeneration'])->toBe(99)
    ->and($this->transport->sent[1]->payload['reset'])->toBeTrue()
    ->and(InputManager::getInputSource()->poll())->toBe(KeyCode::UP)
    ->and($this->runtime->present(null))->toBeFalse();
});

it('restarts an active renderer once without replacing desired field or canvas state and discards only old-session keys', function (bool $canvasMode) {
  $capabilities = ['graphical_canvas', 'sprite_source_rect'];
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), __DIR__,
    requiredCapabilities: $capabilities), $this->transport);
  $this->transport->onStart = static function (FakeRendererTransport $peer) use ($capabilities): void {
    $events = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => $capabilities]))];
    if ($peer->starts > 1) { $events[] = RendererEvent::fromJson('{"protocol":2,"type":"key","key":"right"}'); }
    $peer->batches = [$events];
  };
  $this->runtime->start('Restart retained scene', 12, 4);
  $world = PresentationWorld::getFromLayers(new MapLayerSet([new MapLayer('terrain', 1, false, 'map', 'aabbcc')]));
  $canvas = new PresentationCanvas(320, 180, [new CanvasImage('panel', 'panel.png', new CanvasRectangle(8, 8, 64, 32))]);
  $scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()
    ->onlyMethods(['getPresentationWorld', 'getPresentationCanvas', 'getGraphicalSpriteProviders'])->getMock();
  $scene->method('getPresentationWorld')->willReturn($world);
  $scene->method('getPresentationCanvas')->willReturn($canvasMode ? $canvas : null);
  $player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()
    ->onlyMethods(['getGraphicalSpriteDefinition', 'getGraphicalSpriteWorldPosition'])->getMock();
  $player->method('getGraphicalSpriteDefinition')->willReturn(CharacterSheet::fromArray(characterSheetData())
    ->getFrame(\Ichiloto\Engine\Core\Enumerations\MovementHeading::SOUTH, 1, ['width' => 48, 'height' => 48]));
  $player->method('getGraphicalSpriteWorldPosition')->willReturn(new Vector2(2, 1));
  $scene->method('getGraphicalSpriteProviders')->willReturn([$player]);
  new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, new Camera($scene, 12, 4));
  Console::write('RETAIN ME', 0, 0);
  expect($this->runtime->present($scene))->toBeTrue();
  $initial = RetainedFrameState::replay($this->transport->sent)[0];
  if ($canvasMode) { expect($initial['canvas']['images'][0]['asset'])->toBe('panel.png'); }
  else { expect($initial['worlds']['map']['glyphRows']['map:terrain'][0][1]['glyph'])->toBe('bb')->and($initial['sprites'])->toHaveCount(1); }
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"key","key":"left"}')];
  $this->runtime->pump();
  $input = InputManager::getInputSource(); $console = Console::snapshot(); $session = $this->transport->session;
  $sent = count($this->transport->sent);
  $this->runtime->restart();
  expect(InputManager::getInputSource())->toBe($input)->and(Console::snapshot())->toEqual($console)
    ->and($this->transport->session)->toBe($session)->and($this->transport->starts)->toBe(2)
    ->and($input->poll())->toBe(KeyCode::RIGHT)->and($input->poll())->toBeNull()
    ->and($this->runtime->present($scene))->toBeTrue()->and($this->runtime->present($scene))->toBeFalse();
  $resets = array_slice($this->transport->sent, $sent);
  expect($resets)->toHaveCount(1)->and($resets[0]->payload['reset'])->toBeTrue()
    ->and($resets[0]->payload['baseGeneration'])->toBe(0)->and($resets[0]->payload['generation'])->toBe(1);
  $restored = RetainedFrameState::replay($resets)[0];
  unset($initial['frame'], $restored['frame']);
  expect($restored)->toBe($initial);
})->with(['field and sprites' => [false], 'canvas' => [true]]);

it('restarts after a dead peer reports a shutdown failure and clears the old transport failure', function () {
  $this->runtime->start('Dead peer restart', 12, 4);
  Console::write('PRESERVED', 0, 0); $this->runtime->present(null);
  $input = InputManager::getInputSource();
  $this->transport->running = false;
  $this->transport->failure = new RendererTransportException('old peer died');
  $this->transport->shutdownFailure = new RendererTransportException('dead peer cleanup diagnostic');
  expect(fn() => $this->runtime->pump())->toThrow(RendererTransportException::class, 'old peer died');
  $this->transport->onStart = static function (FakeRendererTransport $peer): void {
    $peer->failure = $peer->shutdownFailure = null;
    $peer->batches = [[RendererEvent::fromJson('{"protocol":2,"type":"ready"}'),
      RendererEvent::fromJson('{"protocol":2,"type":"key","key":"up"}')]];
  };
  $this->runtime->restart();
  expect($this->transport->starts)->toBe(2)->and($this->transport->running)->toBeTrue()
    ->and(InputManager::getInputSource())->toBe($input)->and($input->poll())->toBe(KeyCode::UP)
    ->and($this->runtime->present(null))->toBeTrue()->and($this->runtime->present(null))->toBeFalse()
    ->and($this->transport->sent)->toHaveCount(2)->and($this->transport->sent[1]->payload['generation'])->toBe(1)
    ->and($this->transport->sent[1]->payload['reset'])->toBeTrue();
});

it('refuses restart before start or after final shutdown without acquiring a renderer', function () {
  expect(fn() => $this->runtime->restart())->toThrow(LogicException::class, 'active runtime');
  expect($this->transport->starts)->toBe(0);
  $this->runtime->start('Final shutdown', 12, 4);
  $this->runtime->shutdown();
  expect(fn() => $this->runtime->restart())->toThrow(LogicException::class, 'active runtime');
  expect($this->transport->starts)->toBe(1)->and(InputManager::getInputSource())->toBe($this->previous);
});

it('restarts a real PHP pipe peer with a new retained generation and the same runtime input source', function () {
  $capture = tempnam(sys_get_temp_dir(), 'runtime-restart-');
  $process = new RendererProcessConfig([PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', 'ready_key', $capture]);
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig($process, __DIR__), new ProcessRendererTransport($process));
  try {
    $this->runtime->start('Pipe restart', 12, 4);
    $input = InputManager::getInputSource();
    Console::write('SAME SCENE', 0, 0);
    $this->runtime->present(null);
    $this->runtime->restart();
    expect(InputManager::getInputSource())->toBe($input)->and($input->poll())->toBe(KeyCode::W)
      ->and($input->poll())->toBeNull()->and($this->runtime->present(null))->toBeTrue()
      ->and($this->runtime->present(null))->toBeFalse();
    $this->runtime->shutdown();
    $wire = array_map(fn($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($capture, FILE_IGNORE_NEW_LINES));
    expect(array_column($wire, 'type'))->toBe(['hello', 'frame', 'shutdown', 'hello', 'frame', 'shutdown'])
      ->and(array_column($wire, 'protocol'))->toBe(array_fill(0, 6, 2))
      ->and([$wire[1]['generation'], $wire[4]['generation']])->toBe([1, 1])
      ->and($wire[4]['operations'])->toBe($wire[1]['operations']);
  } finally { unlink($capture); }
});

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

it('resumes a large cold world through a slow real pipe while keeping input and latest scene changes', function (string $scenario) {
  $capture = tempnam(sys_get_temp_dir(), 'retained-cold-');
  $process = new RendererProcessConfig([PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', $scenario, $capture],
    ioBudgetBytes: 8192);
  $transport = new ProcessRendererTransport($process);
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig($process, __DIR__), $transport);
  $world = PresentationWorld::getFromLayers(new MapLayerSet([
    new MapLayer('terrain', 0, false, 'terrain', implode("\n", array_fill(0, 512, str_repeat('..', 256)))),
  ]));
  $scene = new class($world) extends GameScene {
    public function __construct(private readonly PresentationWorld $world) {}
    public function getPresentationWorld(): ?PresentationWorld { return $this->world; }
    public function getPresentationCanvas(): ?PresentationCanvas { return null; }
    public function getGraphicalSpriteProviders(): iterable { return []; }
    public function getPresentationViewport(\Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot|\Ichiloto\Engine\IO\Console\ConsolePresentationChanges $snapshot, array $sprites, array $tiles = []): ?\Ichiloto\Engine\Rendering\Presentation\PresentationViewport { return null; }
  };
  try {
    $this->runtime->start('Cold upload remains nonblocking', 12, 4);
    Console::write('INITIAL', 0, 0);
    $this->runtime->present($scene);
    $keys = []; $commits = 0; $maxPending = 0; $longest = 0;
    $deadline = hrtime(true) + 6_000_000_000;
    $tick = 0;
    do {
      Console::write(sprintf('LATEST %03d', min(++$tick, 60)), 0, 0);
      $start = hrtime(true);
      $this->runtime->present($scene);
      $longest = max($longest, hrtime(true) - $start);
      $maxPending = max($maxPending, $transport->getPendingWriteBytes());
      while (($key = InputManager::getInputSource()->poll()) !== null) {
        $keys[] = $key;
        if ($key === KeyCode::ENTER) { $commits++; }
      }
      usleep(200);
    } while ($commits < 2 && hrtime(true) < $deadline);
    expect($commits)->toBe(2)->and($keys)->toContain(KeyCode::RIGHT)
      ->and($transport->isRunning())->toBeTrue()->and($maxPending)->toBeLessThanOrEqual(1048576)
      ->and($longest / 1e9)->toBeLessThan(0.5)
      ->and($this->runtime->present($scene))->toBeFalse();
    $this->runtime->shutdown();
    // Stream the large world proof rather than retaining a second decoded world in the test process.
    $expected = hash_init('sha256');
    foreach ($world->operations as $operation) {
      if ($operation['op'] === 'worldRows') { hash_update($expected, json_encode($operation)); }
    }
    $expectedHash = hash_final($expected);
    $received = hash_init('sha256'); $cells = 0;
    $replay = new RetainedFrameState(); $frames = [];
    $file = fopen($capture, 'r');
    while (($line = fgets($file)) !== false) {
      $wire = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
      if ($wire['type'] !== 'frame') { continue; }
      if ($wire['reset']) { $received = hash_init('sha256'); $cells = 0; }
      $screen = [];
      foreach ($wire['operations'] as $operation) {
        if ($operation['op'] === 'worldRows') {
          hash_update($received, json_encode($operation));
          foreach ($operation['rows'] as $row) { $cells += count($row['cells']); }
        } elseif (($operation['kind'] ?? null) !== 'world') { $screen[] = $operation; }
      }
      $wire['operations'] = $screen;
      unset($wire['type'], $wire['protocol']);
      $message = new \Ichiloto\Engine\Rendering\Transport\RendererMessage(
        \Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType::FRAME, $wire, RendererProtocolVersion::V2);
      if ($replay->applyMessage($message)) {
        $frames[] = $replay->getFrame();
        expect($cells)->toBe(131072)->and(hash_final(hash_copy($received)))->toBe($expectedHash);
      }
    }
    fclose($file);
    expect(end($frames)['textLayers'][0]['runs'][0]['text'])->toStartWith('LATEST 060');
  } finally {
    $this->runtime->shutdown();
    $this->runtime = null;
    unset($world, $scene);
    gc_collect_cycles();
    unlink($capture);
  }
})->with(['retained_upload_slow', 'retained_upload_drop']);

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
})->with([null, '{"protocol":2,"type":"error","message":"missing image"}']);

it('keeps native close outside input and unwinds waits persistently', function () {
  $this->runtime->start('Test', 12, 4);
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"close_requested"}')];
  expect(fn() => $this->runtime->pump())->toThrow(RendererWindowClosed::class);
  expect(fn() => $this->runtime->pump())->toThrow(RendererWindowClosed::class);
  expect(InputManager::getInputSource()->poll())->toBeNull();
});

it('lets real Game quit on native close without updating gameplay or confirming', function () {
  $game = new RendererRuntimeGameProbe();
  $game->useRendererRuntime($this->runtime);
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready"}')];
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"close_requested"}')];
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
  expect(fn() => $this->runtime->present(null))->toThrow(RuntimeException::class, 'composition');
  Console::endFrame();
  Console::syncDimensions(13, 4);
  expect(fn() => $this->runtime->present(null))->toThrow(InvalidArgumentException::class, 'fixed renderer session grid');
});

it('honors explicit graphical grid dimensions even when they equal the legacy terminal auto defaults', function ($options) {
  $game = new RendererRuntimeGameProbe();
  $game->useRendererRuntime($this->runtime);
  $size = new ReflectionMethod(Game::class, 'resolveScreenSize')->invoke($game, $options);
  expect($size)->toBe(['width' => DEFAULT_SCREEN_WIDTH, 'height' => DEFAULT_SCREEN_HEIGHT]);
})->with([
  [['width' => DEFAULT_SCREEN_WIDTH, 'height' => DEFAULT_SCREEN_HEIGHT]],
  [['screen' => ['width' => DEFAULT_SCREEN_WIDTH, 'height' => DEFAULT_SCREEN_HEIGHT]]],
]);

it('caps terminal auto sizing at the battle footprint', function () {
  $game = new RendererRuntimeGameProbe();
  new ReflectionProperty(Game::class, 'width')->setValue($game, DEFAULT_SCREEN_WIDTH);
  new ReflectionProperty(Game::class, 'height')->setValue($game, DEFAULT_SCREEN_HEIGHT);
  $available = Console::getAvailableSize();
  expect(new ReflectionMethod(Game::class, 'resolveScreenSize')->invoke($game, []))->toBe([
    'width' => min($available['width'], \Ichiloto\Engine\Battle\UI\BattleScreen::WIDTH),
    'height' => min($available['height'], \Ichiloto\Engine\Battle\UI\BattleScreen::HEIGHT),
  ]);
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
  $player->method('getGraphicalSpriteDefinition')->willReturn(CharacterSheet::fromArray(characterSheetData())
    ->getFrame(\Ichiloto\Engine\Core\Enumerations\MovementHeading::SOUTH, 1, ['width' => 48, 'height' => 48]));
  $player->method('getGraphicalSpriteWorldPosition')->willReturn(new Vector2(2, 1));
  new ReflectionProperty(Player::class, 'isActive')->setValue($player, true);
  new ReflectionProperty(GameScene::class, 'player')->setValue($scene, $player);
  $field = makeBareScene(FieldState::class);
  new ReflectionProperty(GameScene::class, 'fieldState')->setValue($scene, $field);
  new ReflectionProperty(GameScene::class, 'state')->setValue($scene, $field);
  // The Player's character frame is a source rect, so the renderer advertises sprite_source_rect.
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["sprite_source_rect"]}')];
  $game->useRendererRuntime($this->runtime);
  $game->startInput();
  Console::write('.', 2, 1);
  Console::withLayer('player', fn() => Console::write('v', 2, 1));
  $game->renderFrame();
  Console::write('Dialogue', 0, 3);
  $game->tickWhileBlocked();
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect($this->transport->sent)->toHaveCount(2)
    ->and($frames[1]['sprites'])->toBe($frames[0]['sprites'])
    ->and(array_values(array_filter($frames[1]['textLayers'][0]['runs'], fn($run) => $run['row'] === 1 && $run['column'] === 2))[0]['text'])->toBe('.')
    ->and(Console::charAt(2, 1))->toBe('v');
  Console::beginFrame();
  $game->tickWhileBlocked();
  Console::endFrame();
  expect($this->transport->sent)->toHaveCount(2);
  $sceneManager->currentScene = null;
  $game->tickWhileBlocked();
  expect(RetainedFrameState::replay($this->transport->sent)[2]['sprites'])->toBe([]);
  $game->quit();
});

it('presents layered field ownership through the real field runtime and clears it on replacement', function () {
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), __DIR__,
    requiredCapabilities: ['sprite_source_rect']), $this->transport);
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["sprite_source_rect"]}')];
  $this->runtime->start('Fixture ownership', 12, 4);
  $scene = makeBareScene(GameScene::class);
  $layers = new MapLayerSet([new MapLayer('floor', 1, false, 'floor', '          '),
    new MapLayer('fixtures', 2, false, 'fixtures', '####  ##i ')]);
  $camera = new Camera($scene, 12, 4, worldSpace: $layers->getComposedGrid());
  new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
  $map = makeBareScene(MapManager::class);
  foreach (['gameScene' => $scene, 'layers' => $layers, 'tileMap' => $camera->worldSpace] as $name => $value) {
    new ReflectionProperty(MapManager::class, $name)->setValue($map, $value);
  }
  new ReflectionProperty(GameScene::class, 'mapManager')->setValue($scene, $map);
  $player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()
    ->onlyMethods(['getGraphicalSpriteDefinition'])->getMock();
  $player->method('getGraphicalSpriteDefinition')->willReturn(null);
  new ReflectionProperty(Player::class, 'isActive')->setValue($player, true);
  new ReflectionProperty(GameScene::class, 'player')->setValue($scene, $player);
  $field = makeBareScene(FieldState::class);
  new ReflectionProperty(GameScene::class, 'fieldState')->setValue($scene, $field);
  new ReflectionProperty(GameScene::class, 'state')->setValue($scene, $field);
  Console::recomposeFrame($map->render(...));
  $terminal = Console::snapshot();
  expect($this->runtime->present($scene))->toBeTrue();
  $frames = RetainedFrameState::replay($this->transport->sent);
  $world = end($frames)['worlds']['map'];
  expect($world['layers'][1]['id'])->toBe('map:fixtures')
    ->and($world['glyphRows']['map:fixtures'][0][4]['glyph'])->toBe('i ')
    ->and($world['glyphRows']['map:floor'][0][2]['glyph'])->toBe('  ')
    ->and(Console::snapshot())->toEqual($terminal);
  Console::withLayer('dialogue', fn() => Console::write('Talk', 0, 3), 1020);
  $this->runtime->present($scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect(end($frames)['worlds']['map'])->toBe($world)
    ->and(array_column(end($this->transport->sent)->payload['operations'], 'op'))->not->toContain('worldTiles', 'worldRows');
  new ReflectionProperty(GameScene::class, 'state')->setValue($scene, makeBareScene(\Ichiloto\Engine\Scenes\Game\States\MainMenuState::class));
  Console::recomposeFrame(fn() => Console::write('Menu', 0, 0));
  $this->runtime->present($scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect(end($frames))->not->toHaveKey('worlds');
  new ReflectionProperty(GameScene::class, 'state')->setValue($scene, $field);
  Console::recomposeFrame($map->render(...));
  $this->runtime->present($scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect(end($frames)['worlds']['map'])->toBe($world)
    ->and(Console::snapshot())->toEqual($terminal);
  $this->runtime->present(null);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect(end($frames))->not->toHaveKey('worlds');
});

it('uses the same field eligibility for the terrain world and Player and clears the world on scene replacement', function () {
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), __DIR__,
    requiredCapabilities:['sprite_source_rect']), $this->transport);
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["sprite_source_rect"]}')];
  $this->runtime->start('Terrain',12,4);
  $scene = makeBareScene(GameScene::class);
  $camera = new Camera($scene,12,4,worldSpace:array_fill(0,4,array_fill(0,12,';;')));
  new ReflectionProperty(GameScene::class,'camera')->setValue($scene,$camera);
  $map = makeBareScene(MapManager::class);
  new ReflectionProperty(MapManager::class,'gameScene')->setValue($map,$scene);
  new ReflectionProperty(MapManager::class,'tileMap')->setValue($map,$camera->worldSpace);
  new ReflectionProperty(MapManager::class,'layers')->setValue($map, new MapLayerSet([
    new MapLayer('terrain', 1, false, 'field', implode("\n", array_fill(0, 4, str_repeat(';;', 12)))),
  ], legacy: true));
  new ReflectionProperty(GameScene::class,'mapManager')->setValue($scene,$map);
  $player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()
    ->onlyMethods(['getGraphicalSpriteDefinition'])->getMock();
  $player->method('getGraphicalSpriteDefinition')->willReturn(null);
  new ReflectionProperty(Player::class,'isActive')->setValue($player,true);
  new ReflectionProperty(GameScene::class,'player')->setValue($scene,$player);
  $field = makeBareScene(FieldState::class);
  new ReflectionProperty(GameScene::class,'fieldState')->setValue($scene,$field);
  new ReflectionProperty(GameScene::class,'state')->setValue($scene,$field);
  // Staging graphical cast asks the scene's Game for the running renderer's asset root.
  [$game] = makeSceneAudioGame();
  $game->useRendererRuntime($this->runtime);
  $sceneManager = makeBareScene(SceneManager::class);
  new ReflectionProperty(SceneManager::class, 'game')->setValue($sceneManager, $game);
  new ReflectionProperty(GameScene::class, 'sceneManager')->setValue($scene, $sceneManager);
  $stage = new \Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager($scene);
  new ReflectionProperty(GameScene::class,'cinematicStage')->setValue($scene,$stage);
  $cast = $stage->add(['id' => 'runner', 'sprite' => '@', 'x' => 3, 'y' => 2,
    'sprites2d' => ['asset' => 'runner.png', 'layer' => 100,
      'sourceRect' => ['x' => 256, 'y' => 0, 'width' => 256, 'height' => 256]]]);
  Console::recomposeFrame(fn()=>$map->render());
  $terminal = Console::snapshot();
  expect($this->runtime->present($scene))->toBeTrue();
  $frames = RetainedFrameState::replay($this->transport->sent);
  $world = $frames[0]['worlds']['map'];
  expect(array_sum(array_map(count(...), $world['glyphRows']['map:terrain'])))->toBe(48)
    ->and($frames[0]['sprites'][0]['id'])->toBe('staged:runner')
    ->and($frames[0]['sprites'][0]['sourceRect']['x'])->toBe(256)
    ->and(Console::snapshot())->toEqual($terminal);
  Console::withLayer('dialogue',fn()=>Console::write('Talk',0,3),1020);
  expect($this->runtime->present($scene))->toBeTrue();
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect(end($frames)['worlds']['map'])->toBe($world)
    ->and(array_column(end($this->transport->sent)->payload['operations'], 'op'))->not->toContain('worldTiles', 'worldRows');
  $cinematic = new \Ichiloto\Engine\Cutscenes\Cinematics\CinematicController($scene);
  new ReflectionProperty(GameScene::class,'cinematicController')->setValue($scene,$cinematic);
  new ReflectionProperty($cinematic,'active')->setValue($cinematic,
    makeBareScene(\Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition::class));
  $this->runtime->present($scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect(end($frames)['worlds']['map'])->toBe($world);
  expect(iterator_to_array($scene->getGraphicalSpriteProviders()))->toBe([$player, $cast]);
  new ReflectionProperty($cinematic,'active')->setValue($cinematic,null);
  $this->runtime->present($scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect(end($frames)['worlds']['map'])->toBe($world);
  // Actual background restoration removes old named Player history.
  Console::withLayer('player',fn()=>Console::write('@',2,1));
  $map->renderBackgroundTile(1,1);
  $this->runtime->present($scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect(end($frames)['worlds']['map']['glyphRows']['map:terrain'][1][1]['glyph'])->toBe(';;')
    ->and(array_column(end($frames)['textLayers'], 'id'))->not->toContain('player');
  $before = Console::getBuffer();
  $map->renderBackgroundTile(-1,0);
  expect(Console::getBuffer())->toBe($before);
  new ReflectionProperty(Player::class,'isActive')->setValue($player,false);
  expect(iterator_to_array($scene->getGraphicalSpriteProviders()))->toBe([]);
  new ReflectionProperty(Player::class,'isActive')->setValue($player,true);
  Console::recomposeFrame(fn()=>Console::write('Menu',0,0));
  new ReflectionProperty(GameScene::class,'state')->setValue($scene,makeBareScene(\Ichiloto\Engine\Scenes\Game\States\MainMenuState::class));
  $this->runtime->present($scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect(end($frames))->not->toHaveKey('worlds')->and(end($frames)['sprites'])->toBe([]);
  new ReflectionProperty(GameScene::class,'state')->setValue($scene,$field);
  Console::recomposeFrame(fn()=>$map->render());
  $this->runtime->present($scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect(end($frames)['worlds']['map'])->toBe($world);
  // A rebuilt world for the same map carries the same cells.
  new ReflectionMethod(MapManager::class, 'clearPresentationWorld')->invoke($map);
  Console::recomposeFrame(fn()=>$map->render());
  $this->runtime->present($scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect(end($frames)['worlds']['map']['glyphRows'])->toBe($world['glyphRows']);
});

it('allows the maximum logical grid with retained worlds independent of the removed 32768 tile viewport cap', function (int $columns, int $rows) {
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), __DIR__,
    requiredCapabilities: ['tile_batches']), $this->transport);
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["tile_batches"]}')];
  $this->runtime->start('Retained world grid', $columns, $rows);
  expect($this->transport->session->grid->columns)->toBe($columns)
    ->and($this->transport->session->grid->rows)->toBe($rows)
    ->and(InputManager::getInputSource())->toBeInstanceOf(RendererInputSource::class);
})->with([[512, 65], [129, 256], [512, 256]]);

it('preserves the maximum protocol grid for runtimes without tile capability requirements', function () {
  $this->runtime->start('Text grid', 512, 256);
  expect($this->transport->session->grid->columns)->toBe(512)
    ->and($this->transport->session->grid->rows)->toBe(256);
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
      $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"close_requested"}')];
      $game->tickWhileBlocked();
      throw new LogicException('Expected the pending native close.');
    } finally { $scene->releaseFieldMusic(); }
  };
  $game->useRendererRuntime($this->runtime);
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready"}')];
  $beforeRun = count($audio->calls);
  $game->run();
  expect($game->updates)->toBe(1)->and($audio->currentBackgroundMusic)->toBeNull()
    ->and(array_slice($audio->calls, $beforeRun))->toBe([
      ['playBackgroundMusic', 'rest'], ['playBackgroundMusic', 'mission-theme'], ['stopBackgroundMusic', null],
    ])
    ->and($this->transport->shutdowns)->toBe(1)
    ->and(InputManager::getInputSource())->toBe($this->previous);
});
