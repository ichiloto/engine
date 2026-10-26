<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\GameObject;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\ModalEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandContext;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandHandlerInterface;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandOutcome;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Events\ModalEvent;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\KeyTransition;
use Ichiloto\Engine\IO\InputSources\HeldInputSourceInterface;
use Ichiloto\Engine\IO\InputSources\InputSourceInterface;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Tests\Support\Input\FakeRendererTransport;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneExcursionResultInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneExcursionOutcome;
use Ichiloto\Engine\Scenes\SceneExcursionSession;
use Ichiloto\Engine\Scenes\SceneExcursionStatus;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Scenes\Game\States\GameSceneState;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\UI\Modal\Modal;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

final class ExcursionTestGame extends Game
{
  public array $tracks = [];
  public function __construct()
  {
    $this->name = 'Synthetic excursion';
    $this->width = 40;
    $this->height = 12;
    $this->sceneManager = new class($this) extends SceneManager {
      public function __construct(Game $game)
      {
        $this->game = $game;
        $this->scenes = new ItemList(SceneInterface::class);
        $this->eventManager = EventManager::getInstance($game);
      }
    };
    $this->audioManager = new class($this) extends AudioManager {
      public function __construct(private ExcursionTestGame $owner) {}
      public function playBackgroundMusic(string $track, bool $loop = true): void { $this->owner->tracks[] = $track; }
      public function stopBackgroundMusic(): void { $this->owner->tracks[] = null; }
    };
  }
  public function __destruct() {}
}

class ExcursionTestCaller extends AbstractScene
{
  public int $starts = 0;
  public int $stops = 0;
  public int $suspends = 0;
  public int $resumes = 0;
  public int $updates = 0;
  public string $failAt = '';
  public bool $resumeHadInput = false;
  public ?Closure $onStop = null;
  public ?Closure $onResume = null;
  private bool $isStopping = false;
  public function __construct(SceneManager $manager) { parent::__construct($manager, 'Caller'); }
  public function getBackgroundMusic(): ?string { return 'caller-track'; }
  public function start(): void { $this->isStopping = false; $this->starts++; parent::start(); }
  public function stop(): void
  {
    if ($this->isStopping) { return; }
    $this->isStopping = true;
    $this->stops++;
    ($this->onStop ?? static fn() => null)();
    parent::stop();
    if ($this->failAt === 'stop') { throw new RuntimeException('caller stop failed'); }
  }
  public function suspend(): void
  {
    $this->suspends++; parent::suspend();
    if ($this->failAt === 'suspend') { throw new RuntimeException('caller suspend failed'); }
  }
  public function resume(): void
  {
    $this->resumes++;
    $this->resumeHadInput = Input::isButtonDown('confirm') || Input::isButtonDown('right');
    parent::resume();
    ($this->onResume ?? static fn() => null)();
    if ($this->failAt === 'resume') { throw new RuntimeException('caller resume failed'); }
    Console::recomposeFrame(fn() => $this->render());
  }
  public function render(): void { Console::write('Caller', 0, 0); }
  public function update(): void { $this->updates++; }
}

final class ExcursionTestOther extends ExcursionTestCaller {}

final class ExcursionTestTarget extends AbstractScene
{
  public int $starts = 0;
  public int $stops = 0;
  public int $updates = 0;
  public int $resumes = 0;
  public string $failAt = '';
  public ?SceneConfigurationInterface $configuration = null;
  public ?Closure $onConfigure = null;
  public ?Closure $onStart = null;
  public bool $configureHadInput = false;
  public function __construct(SceneManager $manager) { parent::__construct($manager, 'Target'); }
  public function getBackgroundMusic(): ?string { return $this->configuration === null ? null : 'target-track'; }
  public function configure(SceneConfigurationInterface $config): void
  {
    $this->configuration = $config;
    $this->configureHadInput = Input::isButtonDown('confirm') || Input::isButtonDown('right');
    ($this->onConfigure ?? static fn() => null)();
    Console::write('Target', 0, 0);
    if ($this->failAt === 'configure') { throw new RuntimeException('target configure failed'); }
  }
  public function start(): void
  {
    $this->starts++; parent::start();
    ($this->onStart ?? static fn() => null)();
    if ($this->failAt === 'start') { throw new RuntimeException('target start failed'); }
  }
  public function stop(): void
  {
    $this->stops++; parent::stop();
    if ($this->failAt === 'stop') { throw new RuntimeException('target stop failed'); }
  }
  public function resume(): void { $this->resumes++; parent::resume(); }
  public function update(): void { $this->updates++; }
}

final readonly class ExcursionTestResult implements SceneExcursionResultInterface
{
  public function __construct(public string $value) {}
}

function getExcursionTestConfig(): SceneConfigurationInterface
{
  return new class implements SceneConfigurationInterface {
    public function __toString(): string { return 'synthetic'; }
    public function serialize(): string { return serialize([]); }
    public function unserialize(string $data): void {}
    public function __serialize(): array { return []; }
    public function __unserialize(array $data): void {}
    public function jsonSerialize(): mixed { return []; }
  };
}

beforeEach(function () {
  $this->staticState = [];
  foreach ([Console::class, InputManager::class, ConfigStore::class, EventManager::class, UIManager::class, ModalManager::class, Debug::class] as $class) {
    $this->staticState[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, null);
  Debug::configure(['log_directory' => createTestDirectory('excursion-log-')]);
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['audio' => ['music' => false, 'sfx' => false]]));
  ConfigStore::put(PlaySettings::class, new PlaySettings(['screenDimensions' => ['width' => 40, 'height' => 12]]));
  $this->input = new class implements InputSourceInterface {
    public int $resets = 0;
    public array $drains = [];
    public ?KeyCode $key = null;
    public function poll(): ?KeyCode { $key = $this->key; $this->key = null; return $key; }
    public function reset(bool $drainBufferedInput = false): void
    { $this->resets++; $this->drains[] = $drainBufferedInput; $this->key = null; }
  };
  InputManager::setInputSource($this->input);
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::ENTER]], 'right' => ['keys' => [KeyCode::RIGHT]]]);
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(40, 12);
  Console::clear();
  $this->game = new ExcursionTestGame();
  new ReflectionProperty(UIManager::class, 'instance')->setValue(null, new class($this->game) extends UIManager {
    public function __construct(Game $game) { parent::__construct($game); }
  });
  $this->manager = $this->game->sceneManager;
  $this->caller = new ExcursionTestCaller($this->manager);
  $this->target = new ExcursionTestTarget($this->manager);
  $this->other = new ExcursionTestOther($this->manager);
  $this->manager->addScenes($this->caller, $this->target, $this->other)->loadScene(ExcursionTestCaller::class);
  $this->caller->render();
});

afterEach(function () {
  $this->caller->failAt = $this->target->failAt = '';
  $this->caller->onStop = $this->caller->onResume = $this->target->onConfigure = null;
  $this->manager->stop();
  foreach ($this->staticState as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('retains the exact caller and shared UI then returns a structured Game result exactly once', function (bool $cancelled) {
  $camera = $this->caller->camera;
  $ui = $this->caller->getUI();
  $config = getExcursionTestConfig();
  $this->input->key = KeyCode::ENTER; InputManager::handleInput();
  $session = $this->manager->beginSceneExcursion($this->caller, ExcursionTestTarget::class, $config);
  $this->manager->update();
  expect($session->outcome)->toBeNull()->and($this->manager->currentScene)->toBe($this->target)
    ->and([$this->caller->stops, $this->caller->updates, $this->caller->suspends])->toBe([0, 0, 1])
    ->and($this->target->updates)->toBe(1)->and($this->target->configuration)->toBe($config)
    ->and($this->target->getUI())->toBe($ui)->and($this->target->configureHadInput)->toBeFalse()
    ->and(Console::charAt(0, 0))->toBe('T');
  $result = new ExcursionTestResult($cancelled ? 'declined' : 'finished');
  $outcome = $cancelled ? SceneExcursionOutcome::cancel($result) : SceneExcursionOutcome::complete($result);
  $this->input->key = KeyCode::RIGHT; InputManager::handleInput();
  $this->manager->finishSceneExcursion($session, $outcome);
  expect($session->outcome)->toBe($outcome)->and($session->outcome->result)->toBe($result)
    ->and($this->manager->currentScene)->toBe($this->caller)->and($this->caller->camera)->toBe($camera)
    ->and([$this->caller->starts, $this->caller->resumes, $this->caller->stops, $this->target->stops])->toBe([1, 1, 0, 1])
    ->and($this->caller->resumeHadInput)->toBeFalse()->and($this->input->drains)->not->toContain(false)
    ->and($this->game->tracks)->toBe(['caller-track', 'target-track', 'caller-track'])
    ->and(Console::charAt(0, 0))->toBe('C');
  expect(fn() => $this->manager->finishSceneExcursion($session, $outcome))->toThrow(LogicException::class);
  expect($this->manager->abandonSceneExcursion($session))->toBeFalse();
  expect($this->manager->getSceneExcursionSession($this->target))->toBeNull();
})->with([false, true]);

it('rejects nested repeated wrong-caller and forged handles before any ownership change', function () {
  $session = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
  [$forged] = SceneExcursionSession::createOwnedSession();
  foreach ([$this->caller, $this->target, $this->other] as $caller) {
    expect(fn() => $this->manager->beginSceneExcursion($caller, 2, getExcursionTestConfig()))->toThrow(LogicException::class);
  }
  expect(fn() => $this->manager->finishSceneExcursion($forged, SceneExcursionOutcome::complete(new ExcursionTestResult('forged'))))->toThrow(LogicException::class);
  expect($this->manager->abandonSceneExcursion($forged))->toBeFalse()
    ->and($this->manager->currentScene)->toBe($this->target)->and($session->outcome)->toBeNull()
    ->and([$this->caller->suspends, $this->target->stops])->toBe([1, 0]);
  expect($this->manager->getSceneExcursionSession($this->target))->toBe($session)
    ->and($this->manager->getSceneExcursionSession($this->caller))->toBeNull();
});

it('prevents a physically held confirmation from leaking across entry and return after idle frames', function () {
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Synthetic excursion input', sys_get_temp_dir(),
    protocol: RendererProtocolVersion::V2, requiredCapabilities: [RendererSessionConfig::KEY_TRANSITIONS]));
  $event = static fn(string $type, array $payload = []): RendererEvent => RendererEvent::fromJson(
    json_encode(['protocol' => 2, 'type' => $type, ...$payload], JSON_THROW_ON_ERROR));
  $transport->batches[] = [$event('ready', ['capabilities' => ['key_transitions']])];
  $client->pump();
  $client->drainEvents();
  InputManager::setInputSource(new RendererInputSource($client));
  $update = static function (RendererEvent ...$events) use ($transport, $client): void {
    $transport->batches[] = $events;
    $client->pump();
    InputManager::handleInput();
  };
  $update($event('key', ['key' => 'enter', 'control' => 'enter', 'repeat' => false]));
  $session = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
  $update();
  $update($event('key', ['key' => 'enter', 'control' => 'enter', 'repeat' => true]));
  expect(Input::isButtonDown('confirm'))->toBeFalse()->and($this->target->configureHadInput)->toBeFalse();
  $this->manager->finishSceneExcursion($session, SceneExcursionOutcome::complete(new ExcursionTestResult('finished')));
  $update();
  $update($event('key', ['key' => 'enter', 'control' => 'enter', 'repeat' => true]));
  expect(Input::isButtonDown('confirm'))->toBeFalse()->and($this->caller->resumeHadInput)->toBeFalse();
  $update($event('key_release', ['control' => 'enter']),
    $event('key', ['key' => 'enter', 'control' => 'enter', 'repeat' => false]));
  expect(Input::isButtonDown('confirm'))->toBeTrue();
  $client->shutdown();
});

it('rolls back failed suspend configuration or startup and permits a fresh invocation', function (string $step) {
  $failingScene = $step === 'suspend' ? $this->caller : $this->target;
  $failingScene->failAt = $step;
  expect(fn() => $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig()))->toThrow(RuntimeException::class);
  expect($this->manager->currentScene)->toBe($this->caller)->and($this->caller->isStarted())->toBeTrue()
    ->and($this->target->isStarted())->toBeFalse()->and([$this->caller->resumes, $this->target->stops])->toBe([1, 1]);
  $this->caller->failAt = $this->target->failAt = '';
  $session = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
  $this->manager->finishSceneExcursion($session, SceneExcursionOutcome::complete(new ExcursionTestResult('retry')));
  expect($session->outcome->status)->toBe(SceneExcursionStatus::COMPLETED);
})->with(['suspend', 'configure', 'start']);

it('abandons on navigation removal unloading direct stop shutdown or replaced current scene without resuming', function (string $exit) {
  $session = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
  $this->caller->onStop = fn() => $this->manager->abandonSceneExcursion($session);
  match ($exit) {
    'navigate' => $this->manager->loadScene(ExcursionTestOther::class),
    'remove-caller' => $this->manager->removeScenes($this->caller),
    'remove-target' => $this->manager->removeScenes($this->target),
    'unload' => $this->manager->unloadScene($this->caller),
    'stop' => $this->caller->stop(),
    'shutdown' => $this->manager->stop(),
    'replace' => $this->manager->currentScene = $this->other,
    default => $this->manager->abandonSceneExcursion($session),
  };
  if ($exit === 'replace') { $this->manager->update(); }
  expect($session->outcome->status)->toBe(SceneExcursionStatus::ABANDONED)
    ->and($this->manager->getSceneExcursionCaller($this->target))->toBeNull()
    ->and([$this->caller->resumes, $this->caller->stops, $this->target->stops])->toBe([0, 1, 1])
    ->and($this->caller->isStarted())->toBeFalse()->and($this->target->isStarted())->toBeFalse();
  if ($this->manager->currentScene === null) { expect(end($this->game->tracks))->toBeNull(); }
  expect(fn() => $this->manager->finishSceneExcursion($session, SceneExcursionOutcome::complete(new ExcursionTestResult('late'))))->toThrow(LogicException::class);
})->with(['navigate', 'remove-caller', 'remove-target', 'unload', 'stop', 'shutdown', 'replace', 'cancel']);

it('cleans both owners if target stop or caller resume fails and records failure instead of success', function (string $step) {
  $session = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
  $failingScene = $step === 'stop' ? $this->target : $this->caller;
  $failingScene->failAt = $step;
  expect(fn() => $this->manager->finishSceneExcursion($session, SceneExcursionOutcome::complete(new ExcursionTestResult('not-committed'))))->toThrow(RuntimeException::class);
  expect($session->outcome->status)->toBe(SceneExcursionStatus::FAILED)->and($session->outcome->result)->toBeNull()
    ->and($this->manager->getSceneExcursionCaller($this->target))->toBeNull()
    ->and($this->manager->currentScene)->toBeNull()->and($this->caller->isStarted())->toBeFalse()
    ->and($this->target->isStarted())->toBeFalse();
})->with(['stop', 'resume']);

it('does not leak inactive scene modal listeners across excursions', function () {
  $events = EventManager::getInstance($this->game);
  $session = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
  $events->dispatchEvent(new ModalEvent(ModalEventType::OPEN, null));
  $events->dispatchEvent(new ModalEvent(ModalEventType::CLOSE, null));
  expect([$this->caller->suspends, $this->caller->resumes, $this->target->resumes])->toBe([1, 0, 1]);
  $this->manager->finishSceneExcursion($session, SceneExcursionOutcome::complete(new ExcursionTestResult('finished')));
  $events->dispatchEvent(new ModalEvent(ModalEventType::OPEN, null));
  $events->dispatchEvent(new ModalEvent(ModalEventType::CLOSE, null));
  expect([$this->caller->suspends, $this->caller->resumes, $this->target->resumes])->toBe([2, 2, 1]);
});

it('rejects an already showing shared presentation rather than stealing its ownership', function () {
  $modal = new class($this->game, 'Synthetic modal') extends Modal {};
  $modal->show();
  expect(fn() => $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig()))->toThrow(LogicException::class);
  expect([$this->caller->suspends, $this->target->starts])->toBe([0, 0])->and($modal->isShowing())->toBeTrue();
  $modal->hide();
  $this->caller->getUI()->commitPresentationChanges();
});

it('does not let stale handles finish or cancel a later session', function () {
  $old = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
  $this->manager->finishSceneExcursion($old, SceneExcursionOutcome::cancel(new ExcursionTestResult('old')));
  $new = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
  expect(fn() => $this->manager->finishSceneExcursion($old, SceneExcursionOutcome::complete(new ExcursionTestResult('late'))))->toThrow(LogicException::class);
  expect($this->manager->abandonSceneExcursion($old))->toBeFalse()->and($new->outcome)->toBeNull()
    ->and($this->manager->currentScene)->toBe($this->target);
});

it('invalidates a directly stopped caller even if it restarts before the next manager tick', function () {
  $session = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
  $this->caller->stop();
  $this->caller->start();
  expect(fn() => $this->manager->finishSceneExcursion($session, SceneExcursionOutcome::complete(new ExcursionTestResult('late'))))->toThrow(LogicException::class);
  expect($session->outcome->status)->toBe(SceneExcursionStatus::ABANDONED)->and($this->caller->resumes)->toBe(0);
});

it('cleans interrupted startup or return without reviving a shutdown caller', function (string $step) {
  if ($step === 'configure') {
    $this->target->onConfigure = fn() => $this->manager->stop();
    expect(fn() => $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig()))->toThrow(LogicException::class);
    expect($this->target->starts)->toBe(0);
  } else {
    $session = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
    $this->caller->onResume = fn() => $this->manager->stop();
    expect(fn() => $this->manager->finishSceneExcursion($session, SceneExcursionOutcome::complete(new ExcursionTestResult('late'))))->toThrow(LogicException::class);
    expect($session->outcome->status)->toBe(SceneExcursionStatus::FAILED);
  }
  expect($this->caller->isStarted())->toBeFalse()->and($this->target->isStarted())->toBeFalse();
})->with(['configure', 'resume']);

it('cleans rollback failures and preserves the original preparation exception as the cause', function () {
  $this->target->failAt = 'configure';
  $this->caller->failAt = 'resume';
  try {
    $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
    test()->fail('Startup should fail.');
  } catch (RuntimeException $error) {
    expect($error->getMessage())->toContain('target configure failed', 'caller resume failed')
      ->and($error->getPrevious()?->getMessage())->toBe('target configure failed');
  }
  expect($this->manager->currentScene)->toBeNull()->and($this->caller->isStarted())->toBeFalse()
    ->and($this->target->isStarted())->toBeFalse();
});

it('rejects missing same started and non-configurable targets without changing state', function () {
  expect(fn() => $this->manager->beginSceneExcursion($this->caller, 'MissingSyntheticScene', getExcursionTestConfig()))
    ->toThrow(\Ichiloto\Engine\Exceptions\NotFoundException::class);
  expect(fn() => $this->manager->beginSceneExcursion($this->caller, 0, getExcursionTestConfig()))->toThrow(LogicException::class);
  $this->target->start();
  expect(fn() => $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig()))->toThrow(LogicException::class);
  $this->target->stop();
  $legacy = test()->createStub(SceneInterface::class);
  $this->manager->addScenes($legacy);
  expect(fn() => $this->manager->beginSceneExcursion($this->caller, 3, getExcursionTestConfig()))->toThrow(LogicException::class);
  expect([$this->caller->suspends, $this->caller->stops])->toBe([0, 0])->and($this->manager->currentScene)->toBe($this->caller);
  $this->manager->loadScene(3);
  expect($this->manager->currentScene)->toBe($legacy);
});

it('does not allow battle entry or untracked suspension to replace an active excursion', function () {
  $session = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
  expect(fn() => $this->manager->loadBattleScene(new \Ichiloto\Engine\Entities\Party(), new \Ichiloto\Engine\Entities\Troop('Synthetic troop')))->toThrow(LogicException::class);
  expect(fn() => $this->manager->loadScene(2, suspendCurrent: true))->toThrow(LogicException::class);
  expect($session->outcome)->toBeNull()->and($this->manager->currentScene)->toBe($this->target)
    ->and([$this->caller->suspends, $this->target->stops])->toBe([1, 0]);
});

it('rejects return while shared modal focus is live and returns only after the owner releases it', function () {
  $session = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
  $modal = new class($this->game, 'Synthetic target modal') extends Modal {};
  $modal->show();
  $outcome = SceneExcursionOutcome::complete(new ExcursionTestResult('after-modal'));
  expect(fn() => $this->manager->finishSceneExcursion($session, $outcome))->toThrow(LogicException::class);
  expect($session->outcome)->toBeNull()->and($this->caller->resumes)->toBe(0);
  $modal->hide(); $this->target->getUI()->commitPresentationChanges();
  $this->manager->finishSceneExcursion($session, $outcome);
  expect($session->outcome)->toBe($outcome);
});

it('attempts every owned scene cleanup and removes listeners even if camera and root stops fail', function () {
  $roots = [];
  for ($index = 0; $index < 2; $index++) {
    $roots[] = new class extends GameObject {
      public int $stops = 0;
      public function __construct() { $this->isActive = true; }
      public function start(): void {}
      public function stop(): void { $this->stops++; throw new RuntimeException('synthetic root stop'); }
    };
  }
  new ReflectionProperty(AbstractScene::class, 'rootGameObjects')->setValue($this->target, $roots);
  new ReflectionProperty(AbstractScene::class, 'camera')->setValue($this->target, new class($this->target, 40, 12) extends Camera {
    public function stop(): void { throw new RuntimeException('synthetic camera stop'); }
  });
  $session = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
  expect(fn() => $this->manager->abandonSceneExcursion($session))->toThrow(RuntimeException::class);
  expect($session->outcome->status)->toBe(SceneExcursionStatus::FAILED)->and($this->target->isStarted())->toBeFalse()
    ->and(array_map(static fn($root) => $root->stops, $roots))->toBe([1, 1]);
  EventManager::getInstance($this->game)->dispatchEvent(new ModalEvent(ModalEventType::CLOSE, null));
  expect($this->target->resumes)->toBe(0);
});

it('clears held movement on entry and return without pretending the terminal reports holds', function () {
  $source = new class implements HeldInputSourceInterface {
    public ?KeyCode $key = null;
    public array $transitions = [];
    public function poll(): ?KeyCode { $key = $this->key; $this->key = null; return $key; }
    public function reset(bool $drainBufferedInput = false): void { $this->key = null; $this->transitions = []; }
    public function canReportHeldState(): bool { return true; }
    public function drainTransitions(): array { $events = $this->transitions; $this->transitions = []; return $events; }
  };
  InputManager::setInputSource($source);
  $source->key = KeyCode::RIGHT; $source->transitions = [KeyTransition::press('arrow-right', KeyCode::RIGHT)];
  InputManager::handleInput();
  expect(Input::isButtonHeld('right'))->toBeTrue();
  $session = $this->manager->beginSceneExcursion($this->caller, 1, getExcursionTestConfig());
  InputManager::handleInput();
  expect(Input::isButtonHeld('right'))->toBeFalse();
  $source->transitions = [KeyTransition::release('arrow-right'), KeyTransition::press('arrow-right', KeyCode::RIGHT)];
  $source->key = KeyCode::RIGHT; InputManager::handleInput();
  expect(Input::isButtonHeld('right'))->toBeTrue();
  $this->manager->finishSceneExcursion($session, SceneExcursionOutcome::cancel(new ExcursionTestResult('cancel')));
  InputManager::handleInput();
  expect(Input::isButtonHeld('right'))->toBeFalse();
  InputManager::setInputSource($this->input);
  expect(Input::isHeldInputAvailable())->toBeFalse();
});

final class ExcursionEventCaller extends GameScene
{
  public array $finishedEvents = [];
  public ?ExcursionEventOperation $operation = null;
  public function __construct(SceneManager $manager)
  {
    AbstractScene::__construct($manager, 'Waiting event caller');
    $this->gameState = new GameState();
    $this->state = new class(new SceneStateContext($this)) extends GameSceneState {
      public function execute(?SceneStateContext $context = null): void {}
    };
    $presentation = new class implements EventPresentationInterface {
      public function beginText(string $text, string $speaker): void {}
      public function beginChoice(string $prompt, array $options, string $title = ''): void {}
      public function update(): void {}
      public function render(): void {}
      public function isComplete(): bool { return true; }
      public function choiceResult(): ?int { return null; }
      public function reset(): void {}
    };
    $catalog = ScriptCommandCatalog::fromDeclarations([
      ['type' => 'synthetic_excursion', 'class' => ExcursionEventCommand::class, 'label' => 'Synthetic excursion', 'fields' => []],
    ], 'synthetic event test');
    $this->eventInterpreter = new EventInterpreter($this, $presentation, $catalog);
  }
  public function onEventSessionStarted(EventExecutionSession $session): void {}
  public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void
  { $this->finishedEvents[] = [$session, $completed]; }
  public function restoreBackgroundMusic(): void { $this->getGame()->audioManager->playBackgroundMusic('event-caller'); }
  public function update(): void { $this->eventInterpreter->update(0); }
  public function render(): void {}
}

final class ExcursionEventCommand implements ScriptCommandHandlerInterface
{
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    assert($context->scene instanceof ExcursionEventCaller);
    $manager = $context->scene->sceneManager;
    $session = $manager->beginSceneExcursion($context->scene, ExcursionTestTarget::class, getExcursionTestConfig());
    $context->scene->operation = $operation = new ExcursionEventOperation($manager, $session);
    return ScriptCommandOutcome::waitFor($operation);
  }
}

final class ExcursionEventOperation implements EventPendingOperationInterface
{
  public int $cancels = 0;
  public ?SceneExcursionOutcome $received = null;
  public function __construct(private SceneManager $manager, public SceneExcursionSession $session) {}
  public function update(float $deltaSeconds): bool
  {
    $this->received = $this->session->outcome;
    if ($this->received === null) { return false; }
    if (in_array($this->received->status, [SceneExcursionStatus::FAILED, SceneExcursionStatus::ABANDONED], true)) {
      throw new RuntimeException('The synthetic excursion did not return.');
    }
    return true;
  }
  public function cancel(): void { $this->cancels++; $this->manager->abandonSceneExcursion($this->session); }
}

it('exposes only the active target\'s actual excursion caller rather than the first scene of its class', function () {
  $first = new ExcursionEventCaller($this->manager);
  $second = new ExcursionEventCaller($this->manager);
  $this->manager->addScenes($first, $second)->loadScene(4);
  expect($this->manager->findScene(ExcursionEventCaller::class))->toBe($first)
    ->and($this->manager->currentScene)->toBe($second)
    ->and($this->manager->getSceneExcursionCaller($this->target))->toBeNull();
  $this->target->onConfigure = function () use ($second): void {
    expect($this->manager->getSceneExcursionCaller($this->target))->toBe($second);
  };
  $this->target->onStart = function () use ($second): void {
    expect($this->manager->getSceneExcursionCaller($this->target))->toBe($second);
  };
  $session = $this->manager->beginSceneExcursion($second, ExcursionTestTarget::class, getExcursionTestConfig());
  expect($this->manager->getSceneExcursionCaller($this->target))->toBe($second)
    ->and($this->manager->getSceneExcursionCaller($second))->toBeNull()
    ->and($this->manager->getSceneExcursionCaller($this->other))->toBeNull()
    ->and($this->manager->getSceneExcursionCaller(new ExcursionTestTarget($this->manager)))->toBeNull();
  $this->manager->finishSceneExcursion($session, SceneExcursionOutcome::complete(new ExcursionTestResult('returned')));
  expect($this->manager->getSceneExcursionCaller($this->target))->toBeNull();

  $this->target->onConfigure = null;
  $this->target->onStart = null;
  $this->manager->loadScene(3);
  $next = $this->manager->beginSceneExcursion($first, ExcursionTestTarget::class, getExcursionTestConfig());
  expect($this->manager->getSceneExcursionCaller($this->target))->toBe($first);
  $this->manager->abandonSceneExcursion($next);
  expect($this->manager->getSceneExcursionCaller($this->target))->toBeNull();
});

it('retains the real waiting event and resumes its Game pending operation exactly once', function (bool $cancelled) {
  $caller = new ExcursionEventCaller($this->manager);
  $this->manager->addScenes($caller)->loadScene(ExcursionEventCaller::class);
  $event = $caller->startEventScript([
    ['type' => 'synthetic_excursion'], ['type' => 'set_variable', 'name' => 'continued', 'value' => 1],
  ], 'synthetic-wait');
  expect($event->status)->toBe(EventExecutionStatus::YIELDED)->and($caller->finishedEvents)->toBe([])
    ->and($this->manager->currentScene)->toBe($this->target)->and($caller->isStarted())->toBeTrue();
  $this->manager->update();
  expect($event->status)->toBe(EventExecutionStatus::YIELDED)->and($caller->gameState->getVariable('continued'))->toBe(0);
  $result = new ExcursionTestResult($cancelled ? 'cancelled' : 'complete');
  $outcome = $cancelled ? SceneExcursionOutcome::cancel($result) : SceneExcursionOutcome::complete($result);
  $this->manager->finishSceneExcursion($caller->operation->session, $outcome);
  $this->manager->update();
  $this->manager->update();
  expect($caller->operation->received)->toBe($outcome)->and($event->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($caller->gameState->getVariable('continued'))->toBe(1)
    ->and($caller->finishedEvents)->toBe([[$event, true]])->and($caller->operation->cancels)->toBe(0);
})->with([false, true]);

it('cancels the real waiting event once on navigation shutdown or event failure', function (string $exit) {
  $caller = new ExcursionEventCaller($this->manager);
  $this->manager->addScenes($caller)->loadScene(ExcursionEventCaller::class);
  $event = $caller->startEventScript([
    ['type' => 'synthetic_excursion'], ['type' => 'set_variable', 'name' => 'continued', 'value' => 1],
  ], 'synthetic-abandon');
  match ($exit) {
    'navigate' => $this->manager->loadScene(ExcursionTestOther::class),
    'shutdown' => $this->manager->stop(),
    default => $caller->eventInterpreter->failActiveSession('Synthetic event cancellation.'),
  };
  expect($event->status)->toBe(EventExecutionStatus::FAILED)
    ->and($caller->operation->session->outcome->status)->toBe(SceneExcursionStatus::ABANDONED)
    ->and($caller->operation->cancels)->toBe(1)->and($caller->gameState->getVariable('continued'))->toBe(0)
    ->and($caller->finishedEvents)->toBe([[$event, false]])->and($caller->isStarted())->toBeFalse()
    ->and($this->target->isStarted())->toBeFalse();
})->with(['navigate', 'shutdown', 'event-failure']);
