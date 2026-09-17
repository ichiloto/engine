<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\GameEventType;
use Ichiloto\Engine\Events\Enumerations\ModalEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\GameEvent;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;
use Ichiloto\Engine\Events\ModalEvent;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\Cursor;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\InputSourceInterface;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\IO\InputSources\TerminalInputSource;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Modal\ConfirmModal;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Modal\SelectModal;
use Ichiloto\Engine\UI\Interfaces\UIElementInterface;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

final class ShutdownInputProbe implements InputSourceInterface
{
  public int $polls = 0;
  public int $resets = 0;
  public bool $throwOnReset = false;
  public ?Closure $onPoll = null;
  public function __construct(public array $keys = []) {}
  public function poll(): ?KeyCode
  {
    if (++$this->polls > 4) { throw new RuntimeException('Stopped modal kept polling.'); }
    ($this->onPoll)?->__invoke();
    return array_shift($this->keys);
  }
  public function reset(bool $drainBufferedInput = false): void
  {
    $this->resets++;
    if ($this->throwOnReset) { throw new RuntimeException('input reset failed'); }
  }
}

/** Real loop/quit/cleanup without bootstrap, terminal modes, native services or saves. */
final class ShutdownInputGame extends Game
{
  public int $renders = 0;
  public int $updates = 0;
  public function __construct()
  {
    $this->options = ['fps' => 1000];
    $this->observers = new ItemList(ObserverInterface::class);
    $this->staticObservers = new ItemList(StaticObserverInterface::class);
    $this->sceneManager = new ReflectionClass(SceneManager::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(SceneManager::class, 'game')->setValue($this->sceneManager, $this);
    new ReflectionProperty(SceneManager::class, 'scenes')->setValue($this->sceneManager, new ItemList(SceneInterface::class));
    $this->audioManager = new class($this) extends RecordingAudioManager {
      public int $shutdowns = 0;
      public int $updates = 0;
      public function shutdown(): void { $this->shutdowns++; }
      public function update(): void { $this->updates++; }
    };
    $this->notificationManager = new class extends NotificationManager {
      public int $updates = 0;
      public int $renders = 0;
      public function __construct() {}
      public function __destruct() {}
      public function update(): void { $this->updates++; }
      public function render(?int $x = null, ?int $y = null): void { $this->renders++; }
    };
  }
  public function __destruct() {}
  protected function start(): void { $this->isRunning = true; }
  protected function syncScreenSize(bool $resizeLogicalViewport = true): void {}
  protected function render(): void { $this->renders++; }
  protected function update(): void { $this->updates++; parent::update(); }
  public function updateFrame(): void { $this->update(); }
  public function pollFrame(): void { $this->handleInput(); }
}

final class ShutdownInputField extends GameScene
{
  public int $musicRefreshes = 0;
  public function __construct(ShutdownInputGame $game)
  {
    $this->sceneManager = $game->sceneManager;
    $this->eventManager = EventManager::getInstance($game);
    $this->uiManager = new ReflectionClass(UIManager::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(UIManager::class, 'uiElements')->setValue($this->uiManager, new ItemList(UIElementInterface::class));
    $this->camera = new class extends Camera {
      public int $stops = 0;
      public ?Closure $onUpdate = null;
      public function __construct() {}
      public function start(): void {}
      public function suspend(): void {}
      public function update(): void { ($this->onUpdate)?->__invoke(); }
      public function stop(): void { $this->stops++; }
    };
    $this->player = new class extends Player {
      public int $interactions = 0;
      public int $moves = 0;
      public ?Closure $onInteract = null;
      public function __construct() {}
      public function interact(): void { $this->interactions++; ($this->onInteract)?->__invoke(); }
      public function move(Vector2 $direction, Camera $camera): void { $this->moves++; }
      public function reconcileActiveEventState(): void {}
    };
    $this->npcManager = new class extends NpcManager {
      public int $updates = 0;
      public function __construct() {}
      public function update(): void { $this->updates++; }
    };
    $this->fieldState = new class(new SceneStateContext($this)) extends FieldState {
      public int $renders = 0;
      public int $resumes = 0;
      public int $navigations = 0;
      public function renderTheField(bool $forceFullRepaint = false): void { $this->renders++; }
      public function resume(): void { $this->resumes++; $this->renderTheField(); }
      protected function handleNavigation(GameScene $scene): void { $this->navigations++; parent::handleNavigation($scene); }
    };
    $this->state = $this->fieldState;
    $this->sceneStateContext = new SceneStateContext($this);
    $game->sceneManager->addScenes($this);
    $game->sceneManager->currentScene = $this;
    $this->start();
  }
  public function refreshFieldMusic(bool $force = false, bool $keepSilence = false): void { $this->musicRefreshes++; }
}

function shutdownModalProbe(Game $game, string $kind): ConfirmModal|SelectModal
{
  if ($kind === 'select') {
    return new class($game, 'Shutdown probe', ['First', 'Second'], 'Select') extends SelectModal {
      public int $renders = 0;
      public int $updates = 0;
      public int $erases = 0;
      public ?Closure $onUpdate = null;
      public function render(?int $x = null, ?int $y = null): void { $this->renders++; }
      public function erase(?int $x = null, ?int $y = null): void { $this->erases++; }
      public function update(): void { $this->updates++; ($this->onUpdate)?->__invoke(); parent::update(); }
    };
  }

  return new class($game, 'Shutdown probe', 'Confirm') extends ConfirmModal {
    public int $renders = 0;
    public int $updates = 0;
    public int $erases = 0;
    public ?Closure $onUpdate = null;
    public function render(): void { $this->renders++; }
    public function erase(): void { $this->erases++; }
    public function update(): void { $this->updates++; ($this->onUpdate)?->__invoke(); parent::update(); }
  };
}

beforeEach(function () {
  $this->staticBefore = [];
  foreach ([Console::class, Cursor::class, InputManager::class, EventManager::class, ModalManager::class,
    ConfigStore::class, Timers::class, Debug::class, AudioManager::class] as $class) {
    $this->staticBefore[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, null);
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  $this->logDirectory = sys_get_temp_dir() . '/ichiloto-shutdown-input-' . bin2hex(random_bytes(8));
  Debug::configure(['log_directory' => $this->logDirectory]);
  Timers::clear();
  Timers::setFrameTick(null);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 24]));
  putSceneAudioConfig([]);
  ob_start();
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, 24);
  $this->game = new ShutdownInputGame();
  $this->scene = new ShutdownInputField($this->game);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, EventManager::getInstance($this->game));
  InputManager::setBindings([
    'quit' => ['keys' => [KeyCode::q]], 'confirm' => ['keys' => [KeyCode::ENTER]],
    'action' => ['keys' => [KeyCode::ENTER]], 'right' => ['keys' => [KeyCode::d]],
  ]);
  $this->input = new ShutdownInputProbe([KeyCode::q, KeyCode::ENTER]);
  InputManager::setInputSource($this->input);
});

afterEach(function () {
  $this->game->quit();
  ob_end_clean();
  foreach ($this->staticBefore as $class => $state) {
    foreach ($state as $name => $value) {
      new ReflectionProperty($class, $name)->setValue(null, $value);
    }
  }
  foreach (glob($this->logDirectory . '/*') ?: [] as $file) { unlink($file); }
  if (is_dir($this->logDirectory)) { rmdir($this->logDirectory); }
});

it('ends the field frame after the actual quit confirmation aliases the action key', function (string $sourceType) {
  $stream = null;
  if ($sourceType === 'terminal') {
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, "q\r");
    rewind($stream);
    InputManager::setInputSource(new TerminalInputSource($stream));
  } elseif ($sourceType === 'renderer') {
    $transport = new FakeRendererTransport();
    $transport->batches[] = [
      RendererEvent::fromJson('{"protocol":1,"type":"key","key":"q"}'),
      RendererEvent::fromJson('{"protocol":1,"type":"key","key":"enter"}'),
    ];
    InputManager::setInputSource(new RendererInputSource(new RendererClient($transport)));
  }
  try {
    InputManager::handleInput();
    $this->scene->update();
    expect($this->scene->player->interactions)->toBe(0)
      ->and($this->scene->fieldState->navigations)->toBe(0)
      ->and($this->scene->player->moves)->toBe(0)
      ->and($this->scene->npcManager->updates)->toBe(0)
      ->and($this->scene->musicRefreshes)->toBe(0)
      ->and($this->scene->isStarted())->toBeFalse()
      ->and($this->game->audioManager->shutdowns)->toBe(1)
      ->and(InputManager::getPressedKeyCode())->toBeNull()
      ->and(Input::isKeyUp(KeyCode::ENTER))->toBeFalse()
      ->and(Input::getAxis(AxisName::HORIZONTAL))->toBe(0.0)
      ->and(ModalManager::getInstance($this->game)->currentModal)->toBeNull();
  } finally {
    if (is_resource($stream)) { fclose($stream); }
  }
})->with(['scripted', 'terminal', 'renderer']);

it('does not navigate wander or refresh field music after an interaction stops the scene', function () {
  InputManager::setBinding('right', [KeyCode::ENTER]);
  $this->input->keys = [KeyCode::ENTER];
  $this->scene->player->onInteract = $this->scene->stop(...);
  InputManager::handleInput();
  $this->scene->update();
  expect($this->scene->player->interactions)->toBe(1)
    ->and($this->scene->fieldState->navigations)->toBe(0)
    ->and($this->scene->npcManager->updates)->toBe(0)
    ->and($this->scene->musicRefreshes)->toBe(0);
});

it('does not execute a field state after an earlier scene update quits', function () {
  $this->input->keys = [KeyCode::ENTER];
  InputManager::handleInput();
  $this->scene->camera->onUpdate = $this->game->quit(...);
  $this->scene->update();
  expect($this->scene->player->interactions)->toBe(0)
    ->and($this->scene->fieldState->navigations)->toBe(0)
    ->and($this->scene->npcManager->updates)->toBe(0)
    ->and($this->scene->musicRefreshes)->toBe(0);
});

it('clears cached input even if source reset fails and still completes scene and platform cleanup', function () {
  $this->input->keys = [KeyCode::ENTER];
  InputManager::handleInput();
  $this->input->throwOnReset = true;
  $this->game->quit();
  $this->game->quit();
  expect(InputManager::getPressedKeyCode())->toBeNull()
    ->and(Input::isKeyUp(KeyCode::ENTER))->toBeFalse()
    ->and($this->scene->camera->stops)->toBe(1)
    ->and($this->game->audioManager->shutdowns)->toBe(1)
    ->and($this->input->resets)->toBe(1)
    ->and(file_get_contents($this->logDirectory . '/error.log'))->toContain('input reset failed');
});

it('returns from the main loop without an update when keyboard dispatch quits', function () {
  EventManager::getInstance($this->game)->addEventListener(EventType::KEYBOARD, $this->game->quit(...));
  $this->game->run();
  $this->game->pollFrame();
  expect($this->input->polls)->toBe(1)->and($this->game->updates)->toBe(0)
    ->and($this->game->renders)->toBe(0)->and(InputManager::getPressedKeyCode())->toBeNull();
});

it('preserves startup modals before the main loop has begun', function (string $kind) {
  $this->input->keys = [KeyCode::ENTER];
  expect(new ReflectionProperty(Game::class, 'isRunning')->getValue($this->game))->toBeFalse()
    ->and(shutdownModalProbe($this->game, $kind)->open())->toBe($kind === 'select' ? 0 : true)
    ->and($this->input->polls)->toBe(1)
    ->and($this->scene->isStarted())->toBeTrue();
})->with(['confirm', 'select']);

it('returns the normal close override result exactly once', function (string $kind) {
  $this->input->keys = [KeyCode::ENTER];
  $modal = $kind === 'select'
    ? new class($this->game, 'Choose', ['First']) extends SelectModal {
      public int $closes = 0;
      public function close(): int { $this->closes++; parent::close(); return 7; }
    }
    : new class($this->game, 'Confirm', 'Confirm') extends ConfirmModal {
      public int $closes = 0;
      public function close(): mixed { $this->closes++; parent::close(); return 'close-result'; }
    };

  expect($modal->open())->toBe($kind === 'select' ? 7 : 'close-result')
    ->and($modal->closes)->toBe(1)
    ->and($modal->isShowing())->toBeFalse();
})->with(['confirm', 'select']);

it('unwinds a modal without further polling updates rendering or scene resume after quit', function (string $phase, string $kind) {
  $this->input->keys = [null];
  $closed = 0;
  EventManager::getInstance($this->game)->addEventListener(EventType::MODAL,
    function (ModalEvent $event) use (&$closed): void {
      if ($event->modalEventType === ModalEventType::CLOSE) { $closed++; }
    });
  $modal = shutdownModalProbe($this->game, $kind);
  if ($phase === 'before open') { $this->game->quit(); }
  if ($phase === 'input dispatch') {
    $this->input->keys = [KeyCode::ENTER];
    EventManager::getInstance($this->game)->addEventListener(EventType::KEYBOARD, $this->game->quit(...));
  }
  if ($phase === 'modal update') { $modal->onUpdate = $this->game->quit(...); }
  if ($phase === 'update throws after quit') {
    $modal->onUpdate = function (): void {
      $this->game->quit();
      throw new LogicException('primary modal failure');
    };
  }
  if ($phase === 'blocked frame') {
    Timers::setFrameTick($this->game->quit(...));
  }
  if ($phase === 'update throws after quit') {
    expect($modal->open(...))->toThrow(LogicException::class, 'primary modal failure');
  } else {
    expect($modal->open())->toBe($kind === 'select' ? -1 : null);
  }
  $modal->close();
  expect($modal->isShowing())->toBeFalse()
    ->and($modal->renders)->toBe($phase === 'before open' ? 0 : 1)
    ->and($modal->erases)->toBe(0)
    ->and($modal->updates)->toBe(in_array($phase, ['before open', 'input dispatch'], true) ? 0 : 1)
    ->and($this->input->polls)->toBe($phase === 'before open' ? 0 : 1)
    ->and($this->scene->fieldState->resumes)->toBe(0)
    ->and($this->scene->fieldState->renders)->toBe(0)
    ->and($closed)->toBe(0);
})->with(['before open', 'input dispatch', 'modal update', 'blocked frame', 'update throws after quit'])
  ->with(['confirm', 'select']);

it('stops a blocked frame immediately when its update observer quits', function () {
  $this->game->addObserver(new class($this->game) implements ObserverInterface {
    public function __construct(private Game $game) {}
    public function onNotify(object $entity, EventInterface $event): void
    {
      if ($event instanceof GameEvent && $event->getGameEventType() === GameEventType::UPDATE) { $this->game->quit(); }
    }
  });
  $timerCalls = 0;
  Timers::after(0, function () use (&$timerCalls): void { $timerCalls++; });
  $this->game->tickWhileBlocked();
  expect($timerCalls)->toBe(0)
    ->and($this->game->notificationManager->updates)->toBe(0)
    ->and($this->game->notificationManager->renders)->toBe(0)
    ->and($this->game->audioManager->updates)->toBe(0);
});
