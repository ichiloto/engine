<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Battle\Enumerations\BattleEngineType;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicController;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\ModalEventType;
use Ichiloto\Engine\Events\Enumerations\SceneEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Events\Interpreter\EventSessionCompletionTargetInterface;
use Ichiloto\Engine\Events\ModalEvent;
use Ichiloto\Engine\Events\SceneEvent;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\Cursor;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleLoader;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\GameOver\GameOverScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

final class TeardownGame extends Game
{
  public int $engineSelections = 0;
  public function __construct()
  {
    $this->observers = new ItemList(ObserverInterface::class);
    $this->staticObservers = new ItemList(StaticObserverInterface::class);
    // Only lookup maps production class names to headless presentation fixtures;
    // every manager lifecycle/transition method remains the production method.
    $this->sceneManager = new class extends SceneManager {
      public function __construct() {}
      public function findScene(string $className): ?SceneInterface
      {
        return $this->scenes->find(fn(SceneInterface $scene) => $scene instanceof $className);
      }
    };
    new ReflectionProperty(SceneManager::class, 'scenes')->setValue($this->sceneManager, new ItemList(SceneInterface::class));
    new ReflectionProperty(SceneManager::class, 'game')->setValue($this->sceneManager, $this);
    new ReflectionProperty(SceneManager::class, 'eventManager')->setValue($this->sceneManager, EventManager::getInstance($this));
    new ReflectionProperty(SceneManager::class, 'battleLoader')->setValue($this->sceneManager, new class extends BattleLoader {
      public function __construct() {}
      public function newConfig(Party $party, Troop $troop, array $battleEvents, array $extraSettings = []): BattleConfig
      {
        return new BattleConfig($party, $troop, $battleEvents, $extraSettings);
      }
    });
    new ReflectionProperty(SceneManager::class, 'saveManager')->setValue($this->sceneManager, new class extends SaveManager {
      public int $writes = 0;
      public function __construct() {}
      public function autoSave(GameScene $scene): SaveSlot
      {
        $this->writes++;
        throw new RuntimeException('Shutdown must not save.');
      }
    });
    $this->audioManager = new class($this) extends RecordingAudioManager {
      public int $shutdowns = 0;
      public function shutdown(): void { $this->shutdowns++; parent::shutdown(); }
    };
  }
  public function __destruct() {}
  public function updateAfterQuit(): void { parent::update(); }
  public function useBattleEngineType(BattleEngineType $type): void { $this->engineSelections++; }
}

final class TeardownBattle extends BattleScene
{
  public int $configures = 0;
  public function __construct(TeardownGame $game)
  {
    $this->sceneManager = $game->sceneManager;
    $this->eventManager = EventManager::getInstance($game);
    $this->camera = new class($this, 24, 8) extends Camera {
      public int $starts = 0;
      public int $stops = 0;
      public function start(): void { $this->starts++; }
      public function stop(): void { $this->stops++; }
      public function suspend(): void {}
    };
  }
  public function configure(SceneConfigurationInterface $config): void
  {
    $this->configures++;
    $this->config = $config;
    Console::write('BATTLE', 0, 0);
  }
}

final class TeardownGameOver extends GameOverScene
{
  public function __construct() {}
  public function start(): void { $this->started = true; }
  public function stop(): void { $this->started = false; }
}

final class TeardownTitle extends TitleScene
{
  public function __construct() {}
  public function start(): void { $this->started = true; }
  public function stop(): void { $this->started = false; }
}

final class TeardownProbeScene extends AbstractScene
{
  public int $stops = 0;
  public function __construct(bool $started, private ?Closure $onStop = null) { $this->started = $started; }
  public function stop(): void { $this->stops++; ($this->onStop)?->__invoke(); $this->started = false; }
}

final class TeardownPresentation implements EventPresentationInterface
{
  public int $resets = 0;
  public ?Closure $onReset = null;
  public function beginText(string $text, string $name = ''): void {}
  public function beginChoice(string $prompt, array $options, string $title = ''): void {}
  public function update(): void {}
  public function render(): void {}
  public function isComplete(): bool { return false; }
  public function choiceResult(): ?int { return null; }
  public function reset(): void { $this->resets++; ($this->onReset)?->__invoke(); }
}

final class TeardownOutcome implements EventSessionCompletionTargetInterface
{
  public int $completed = 0;
  public int $failed = 0;
  public ?Closure $onFailure = null;
  public function onEventSessionCompleted(GameScene $gameScene, EventExecutionSession $session): void { $this->completed++; }
  public function onEventSessionFailed(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->failed++;
    ($this->onFailure)?->__invoke();
  }
}

/** Real field ownership and lifecycle; no project bootstrap, native renderer or audio backend. */
final class TeardownField extends GameScene
{
  public TeardownPresentation $testPresentation;
  public int $musicRefreshes = 0;
  public function __construct(TeardownGame $game)
  {
    $this->sceneManager = $game->sceneManager;
    $this->eventManager = EventManager::getInstance($game);
    $this->gameState = new GameState();
    $this->party = new class extends Party { public function isDefeated(): bool { return false; } };
    $this->currentMapId = 'teardown-map';
    $this->mapManager = new class extends MapManager {
      public function __construct() {}
      public function canMoveTo(int $x, int $y, ?CollisionType &$collisionType = null): bool { return true; }
    };
    $this->camera = new class($this, 24, 8, worldSpace: array_fill(0, 30, str_repeat('.', 60))) extends Camera {
      public int $stops = 0;
      public int $starts = 0;
      public int $suspends = 0;
      public bool $throwOnStop = false;
      public function start(): void { $this->starts++; }
      public function suspend(): void { $this->suspends++; }
      public function stop(): void
      {
        $this->stops++;
        if ($this->throwOnStop) { throw new RuntimeException('camera stop failed'); }
      }
    };
    $this->fieldState = new class(new SceneStateContext($this)) extends FieldState {
      public int $renders = 0;
      public int $resumes = 0;
      public function renderTheField(bool $forceFullRepaint = false): void { $this->renders++; }
      public function resume(): void { $this->resumes++; }
    };
    $this->state = $this->fieldState;
    $this->npcManager = new NpcManager($this);
    $this->npcManager->configure([['id' => 'guide', 'name' => 'Guide', 'sprite' => 'N', 'x' => 7, 'y' => 4]]);
    $this->cinematicStage = new CinematicStageManager($this);
    $this->cinematicPresentation = new CinematicPresentationManager($this);
    $this->cinematicController = new CinematicController($this);
    $this->testPresentation = new TeardownPresentation();
    $this->eventInterpreter = new EventInterpreter($this, $this->testPresentation);
    $game->sceneManager->addScenes($this);
    $game->sceneManager->currentScene = $this;
    $this->start();
  }
  public function renderBackgroundTile(int $x, int $y): void {}
  public function refreshFieldMusic(bool $force = false, bool $keepSilence = false): void { $this->musicRefreshes++; }
  public function restoreBackgroundMusic(): void {}
  public function deferFieldWork(): void
  {
    $this->hasDeferredAutoSave = true;
    $this->hasPendingAutomaticTriggerEvaluation = true;
  }
}

function teardownCinematic(): CinematicDefinition
{
  return CinematicDefinition::fromArrays([
    'id' => 'teardown', 'name' => 'Teardown',
    'cast' => [['id' => 'pose', 'sprite' => '@', 'subject' => ['kind' => 'npc', 'id' => 'guide']]],
    'finalizer' => [['type' => 'set_switch', 'name' => 'must-not-run', 'value' => true]],
  ], [['type' => 'parallel', 'lanes' => [
    [['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'guide', 'secondsPerStep' => 1,
      'steps' => [['direction' => 'right', 'count' => 5]]]],
    [['type' => 'camera', 'operation' => 'shake', 'seconds' => 10]],
  ]]]);
}

beforeEach(function () {
  $this->staticBefore = [];
  foreach ([Console::class, Cursor::class, InputManager::class, EventManager::class,
    ConfigStore::class, Timers::class, Debug::class] as $class) {
    $this->staticBefore[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->logDirectory = sys_get_temp_dir() . '/ichiloto-teardown-' . bin2hex(random_bytes(8));
  $this->previousDirectory = getcwd();
  Debug::configure(['log_directory' => $this->logDirectory]);
  putSceneAudioConfig(['save' => ['autosave' => true]]);
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  ob_start();
  Console::syncDimensions(24, 8);
  Console::setTerminalOutputEnabled(false);
  $this->game = new TeardownGame();
});

afterEach(function () {
  $this->game->quit();
  chdir($this->previousDirectory);
  ob_end_clean();
  foreach ($this->staticBefore as $class => $state) {
    foreach ($state as $name => $value) {
      new ReflectionProperty($class, $name)->setValue(null, $value);
    }
  }
  if (is_dir($this->logDirectory . '/assets')) {
    unlink($this->logDirectory . '/assets/Data/troops.php');
    rmdir($this->logDirectory . '/assets/Data');
    rmdir($this->logDirectory . '/assets');
  }
  foreach (glob($this->logDirectory . '/*') ?: [] as $file) { unlink($file); }
  if (is_dir($this->logDirectory)) { rmdir($this->logDirectory); }
  elseif (is_file($this->logDirectory)) { unlink($this->logDirectory); }
});

it('continues scene and platform cleanup once after a scene throws, including a partial start', function () {
  $broken = new TeardownProbeScene(true, function () {
    $this->game->tickWhileBlocked();
    throw new RuntimeException('scene stop failed');
  });
  $active = new TeardownProbeScene(true);
  $partial = new TeardownProbeScene(false);
  $unused = new TeardownProbeScene(false);
  $this->game->sceneManager->addScenes($broken, $active, $partial, $unused);
  $this->game->sceneManager->currentScene = $partial;
  $transport = new FakeRendererTransport();
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), __DIR__,
    protocol: RendererProtocolVersion::V1), $transport);
  $runtime->start('Teardown test', 24, 8);
  $this->game->useRendererRuntime($runtime);
  Timers::setFrameTick(fn() => throw new RuntimeException('stale frame callback'));
  $this->game->quit();
  $this->game->quit();
  $this->game->updateAfterQuit();
  expect([$broken->stops, $active->stops, $partial->stops, $unused->stops])->toBe([1, 1, 1, 0])
    ->and($this->game->audioManager->shutdowns)->toBe(1)
    ->and($transport->shutdowns)->toBe(1)
    ->and(new ReflectionProperty(Timers::class, 'frameTick')->getValue())->toBeNull()
    ->and(file_get_contents($this->logDirectory . '/error.log'))->toContain('scene stop failed');
});

it('releases a cinematic route, effect, real-subject lease and input without a finalizer or duplicate outcome', function () {
  $scene = new TeardownField($this->game);
  $outcome = new TeardownOutcome();
  $session = $scene->startCinematic(teardownCinematic(), $outcome);
  $scene->eventInterpreter->update(0.1);
  $scene->eventInterpreter->update(0.1);
  $lanes = $session->rootLane()->parallelGroup->lanes();
  $route = $lanes[0]->pendingOperation;
  $effect = $lanes[1]->pendingOperation;
  $npc = $scene->npcManager->findById('guide');
  expect($npc->position->x)->toBe(8.0)->and($scene->cinematicStage->suppresses($npc))->toBeTrue();
  $scene->cinematicPresentation->hideField();
  $scene->cinematicPresentation->showOverlay('title_card', 'temporary');
  $scene->gameState->setSwitch('committed', true);
  $scene->deferFieldWork();
  $renders = $scene->fieldState->renders;
  $scene->testPresentation->onReset = function () use ($scene): void {
    $scene->resume();
    $scene->restoreFieldAfterOverlay();
    $scene->autoSave();
  };
  $outcome->onFailure = function () use ($scene): void {
    $scene->stop();
    expect($scene->startCinematic('not-a-real-asset'))->toBeNull()
      ->and($scene->startEventScript([['type' => 'wait', 'seconds' => 10]]))->toBeNull();
  };
  $this->game->quit();
  $this->game->quit();
  expect($session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($route->isComplete)->toBeTrue()->and($effect->isComplete)->toBeTrue()
    ->and($scene->hasUnstableEventSession())->toBeFalse()
    ->and($scene->cinematicController->active())->toBeNull()
    ->and($scene->cinematicStage->all())->toBe([])
    ->and($scene->cinematicStage->suppresses($npc))->toBeFalse()
    ->and($scene->cinematicPresentation->hasTransitionCover())->toBeFalse()
    ->and(new ReflectionProperty(CinematicPresentationManager::class, 'overlay')->getValue($scene->cinematicPresentation))->toBeNull()
    ->and($npc->position->x)->toBe(7.0)
    ->and($scene->camera->followsPlayer)->toBeTrue()
    ->and($scene->fieldState->renders)->toBe($renders)->and($scene->fieldState->resumes)->toBe(0)
    ->and($scene->musicRefreshes)->toBe(0)->and($this->game->sceneManager->saveManager->writes)->toBe(0)
    ->and($scene->hasDeferredAutoSave)->toBeFalse()
    ->and(new ReflectionProperty(GameScene::class, 'hasPendingAutomaticTriggerEvaluation')->getValue($scene))->toBeFalse()
    ->and($scene->gameState->getSwitch('committed'))->toBeTrue()
    ->and($scene->gameState->getSwitch('must-not-run'))->toBeFalse()
    ->and($scene->hasStoryEvent('cinematic:teardown:completed'))->toBeFalse()
    ->and([$outcome->completed, $outcome->failed, $scene->camera->stops])->toBe([0, 1, 1]);
});

it('continues platform teardown even when reporting a scene cleanup error also throws', function () {
  file_put_contents($this->logDirectory, 'not a log directory');
  $broken = new TeardownProbeScene(true, fn() => throw new RuntimeException('scene cleanup failed'));
  $later = new TeardownProbeScene(true);
  $this->game->sceneManager->addScenes($broken, $later);
  set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
  });
  try {
    $this->game->quit();
  } finally {
    restore_error_handler();
  }
  expect([$broken->stops, $later->stops, $this->game->audioManager->shutdowns])->toBe([1, 1, 1]);
});

it('fails an ordinary event and cancels its route without rolling back committed movement', function () {
  $scene = new TeardownField($this->game);
  $outcome = new TeardownOutcome();
  $session = $scene->startEventScript([['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'guide',
    'steps' => [['direction' => 'right', 'count' => 5]]]], 'ordinary', $outcome);
  $scene->eventInterpreter->update(0.1);
  $route = $session->rootLane()->pendingOperation;
  $position = $scene->npcManager->findById('guide')->position->x;
  $this->game->quit();
  expect($session->status)->toBe(EventExecutionStatus::FAILED)->and($route->isComplete)->toBeTrue()
    ->and($scene->npcManager->findById('guide')->position->x)->toBe($position)
    ->and($scene->hasUnstableEventSession())->toBeFalse()
    ->and([$outcome->completed, $outcome->failed])->toBe([0, 1]);
});

it('allows a stopped field to start afresh without reviving the previous continuation', function () {
  $scene = new TeardownField($this->game);
  $outcome = new TeardownOutcome();
  $session = $scene->startCinematic(CinematicDefinition::fromArrays(['id' => 'completed', 'name' => 'Completed'],
    [['type' => 'wait', 'seconds' => 10]]), $outcome);
  $scene->deferFieldWork();
  $scene->stop();
  $scene->start();
  expect($scene->isStarted())->toBeTrue()
    ->and($scene->startEventScript([['type' => 'wait', 'seconds' => 10]]))->not->toBeNull();
  $this->game->quit();
  expect($session->status)->toBe(EventExecutionStatus::FAILED)
    ->and([$outcome->completed, $outcome->failed])->toBe([0, 1]);
});

it('does not repeat a completed cinematic outcome or undo committed transforms at quit', function () {
  $scene = new TeardownField($this->game);
  $outcome = new TeardownOutcome();
  $session = $scene->startCinematic(CinematicDefinition::fromArrays([
    'id' => 'completed', 'name' => 'Completed',
    'cast' => [['id' => 'pose', 'sprite' => '@', 'subject' => ['kind' => 'npc', 'id' => 'guide']]],
  ], [['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'guide',
    'steps' => [['direction' => 'right', 'count' => 1]]]]), $outcome);
  $scene->eventInterpreter->update(1);
  expect($session->status)->toBe(EventExecutionStatus::COMPLETED);
  $this->game->quit();
  expect([$outcome->completed, $outcome->failed])->toBe([1, 0])
    ->and($scene->npcManager->findById('guide')->position->x)->toBe(8.0)
    ->and($scene->hasStoryEvent('cinematic:completed:completed'))->toBeTrue()
    ->and($this->game->sceneManager->saveManager->writes)->toBe(0);
});

it('releases cinematic ownership even when a failure callback and camera stop throw', function () {
  $scene = new TeardownField($this->game);
  $outcome = new TeardownOutcome();
  $outcome->onFailure = fn() => throw new RuntimeException('outcome callback failed');
  $scene->camera->throwOnStop = true;
  $session = $scene->startCinematic(teardownCinematic(), $outcome);
  $later = new TeardownProbeScene(true);
  $this->game->sceneManager->addScenes($later);
  $this->game->quit();
  expect($session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($scene->hasUnstableEventSession())->toBeFalse()
    ->and($scene->cinematicController->active())->toBeNull()
    ->and($scene->cinematicStage->all())->toBe([])
    ->and($scene->isStarted())->toBeFalse()
    ->and([$outcome->failed, $later->stops, $this->game->audioManager->shutdowns])->toBe([1, 1, 1]);
});

it('disposes an ordinary event at a true scene unload', function () {
  $scene = new TeardownField($this->game);
  $outcome = new TeardownOutcome();
  $session = $scene->startEventScript([['type' => 'wait', 'seconds' => 10]], 'continuation', $outcome);
  $this->game->sceneManager->unloadScene($scene);
  expect($session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($scene->hasUnstableEventSession())->toBeFalse()
    ->and([$outcome->failed, $scene->camera->stops])->toBe([1, 1]);
});

function startTeardownBattle(TeardownGame $game, string $directory, bool $cinematic, bool $advance = true, bool $immediate = false): array
{
  mkdir($directory . '/assets/Data', 0777, true);
  file_put_contents($directory . '/assets/Data/troops.php', '<?php return [["name" => "Teardown Troop", "enemies" => []]];');
  chdir($directory);
  ConfigStore::put(EnemyStore::class, makeBareScene(EnemyStore::class));
  $scene = new TeardownField($game);
  $battle = new TeardownBattle($game);
  $game->sceneManager->addScenes($battle, new TeardownGameOver(), new TeardownTitle());
  $outcome = new TeardownOutcome();
  $commands = [
    ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'guide', 'steps' => [['direction' => 'right', 'count' => 1]]],
    ['type' => 'start_battle', 'troop' => 'Teardown Troop', 'resultVariable' => 'battle-result'],
    ['type' => 'set_variable', 'name' => 'after-battle', 'value' => 1],
  ];
  if ($immediate) { array_shift($commands); }
  $session = $cinematic
    ? $scene->startCinematic(CinematicDefinition::fromArrays([
      'id' => 'battle-continuation', 'name' => 'Battle Continuation',
      'presentation' => ['initial' => 'black'],
      'cast' => [['id' => 'pose', 'sprite' => '@', 'subject' => ['kind' => 'npc', 'id' => 'guide']]],
    ], $commands), $outcome)
    : $scene->startEventScript($commands, 'battle-continuation', $outcome);
  if ($advance) { $scene->eventInterpreter->update(1); }
  return [$scene, $battle, $session, $outcome];
}

it('suspends real scripted battle ownership and resumes it exactly once without restarting either scene', function (bool $cinematic) {
  $events = [];
  EventManager::getInstance($this->game)->addEventListener(EventType::SCENE,
    function (SceneEvent $event) use (&$events): void { $events[] = $event->sceneEventType; });
  [$scene, $battle, $session, $outcome] = startTeardownBattle($this->game, $this->logDirectory, $cinematic);
  expect($session->failureMessage)->toBeNull()->and($session->status)->toBe(EventExecutionStatus::SUSPENDED)
    ->and($this->game->sceneManager->currentScene)->toBe($battle)
    ->and($scene->isStarted())->toBeTrue()->and($scene->camera->stops)->toBe(0)
    ->and($scene->camera->suspends)->toBe(1)
    ->and($scene->npcManager->findById('guide')->position->x)->toBe(8.0)
    ->and($events)->toContain(SceneEventType::SUSPEND)->not->toContain(SceneEventType::UNLOAD);
  if ($cinematic) {
    expect($scene->cinematicController->active())->not->toBeNull()
      ->and($scene->cinematicStage->all())->toHaveCount(1);
  }
  $battle->result = new BattleResult('Victory');
  $battle->stop(); // The existing BattleEndState stops before handing back.
  $this->game->sceneManager->returnFromBattleScene();
  $this->game->sceneManager->returnFromBattleScene();
  $scene->eventInterpreter->update(1);
  expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($this->game->sceneManager->currentScene)->toBe($scene)
    ->and($scene->gameState->getVariable('battle-result'))->toBe('victory')
    ->and($scene->gameState->getVariable('after-battle'))->toBe(1)
    ->and([$outcome->completed, $outcome->failed])->toBe([1, 0])
    ->and([$scene->camera->starts, $scene->fieldState->resumes, $battle->camera->starts,
      $battle->camera->stops, $battle->configures, $this->game->engineSelections])->toBe([1, 1, 1, 1, 1, 1])
    ->and($events)->toContain(SceneEventType::RESUME);
})->with([false, true]);

it('does not resume or repaint a suspended field when a battle modal closes', function () {
  [$scene, $battle, $session] = startTeardownBattle($this->game, $this->logDirectory, true);
  $renders = $scene->fieldState->renders;
  $events = EventManager::getInstance($this->game);
  $events->dispatchEvent(new ModalEvent(ModalEventType::OPEN, null));
  $events->dispatchEvent(new ModalEvent(ModalEventType::CLOSE, null));
  expect($scene->fieldState->resumes)->toBe(0)->and($scene->fieldState->renders)->toBe($renders)
    ->and($scene->camera->suspends)->toBe(1)->and($session->status)->toBe(EventExecutionStatus::SUSPENDED)
    ->and($this->game->sceneManager->currentScene)->toBe($battle);
});

it('rejects untracked or nested suspension without disturbing the active battle caller', function () {
  $scene = new TeardownField($this->game);
  $this->game->sceneManager->addScenes(new TeardownBattle($this->game), new TeardownTitle());
  foreach ([TitleScene::class, BattleScene::class] as $target) {
    expect(fn() => $this->game->sceneManager->loadScene($target, suspendCurrent: true))->toThrow(LogicException::class);
  }
  expect($this->game->sceneManager->currentScene)->toBe($scene)->and($scene->camera->suspends)->toBe(0);
  $this->game->sceneManager->loadBattleScene($scene->party, new Troop('Test'));
  expect(fn() => $this->game->sceneManager->loadBattleScene($scene->party, new Troop('Nested')))->toThrow(LogicException::class)
    ->and($this->game->engineSelections)->toBe(1)->and($scene->camera->suspends)->toBe(1);
  $this->game->sceneManager->returnFromBattleScene();
  expect($this->game->sceneManager->currentScene)->toBe($scene)->and($scene->fieldState->resumes)->toBe(1);
});

it('does not paint a cinematic over battle when control changes during controller start or a field tick', function (bool $immediate) {
  [$scene, $battle, $session] = startTeardownBattle($this->game, $this->logDirectory, true, advance: false, immediate: $immediate);
  $renders = $scene->fieldState->renders;
  if (! $immediate) {
    $scene->fieldState->execute();
  }
  expect($session->failureMessage)->toBeNull()->and($session->status)->toBe(EventExecutionStatus::SUSPENDED)
    ->and($this->game->sceneManager->currentScene)->toBe($battle)
    ->and($scene->fieldState->renders)->toBe($renders)
    ->and(Console::charAt(0, 0))->toBe('B');
})->with([false, true]);

it('disposes retained battle callers on quit or abandonment without completion or autosave', function (bool $cinematic, string $exit) {
  [$scene, $battle, $session, $outcome] = startTeardownBattle($this->game, $this->logDirectory, $cinematic);
  $scene->deferFieldWork();
  $renders = $scene->fieldState->renders;
  if ($exit === 'quit') {
    $this->game->quit();
  } elseif ($exit === 'defeat') {
    // Mirrors the existing default-defeat callback before the game-over load.
    $scene->failEventAfterBattle('Default defeat');
    $this->game->sceneManager->loadGameOverScene();
  } else {
    $this->game->sceneManager->loadScene(TitleScene::class);
  }
  expect($session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($scene->hasUnstableEventSession())->toBeFalse()
    ->and($scene->cinematicStage->all())->toBe([])->and($scene->cinematicController->active())->toBeNull()
    ->and($scene->npcManager->findById('guide')->position->x)->toBe($cinematic ? 7.0 : 8.0)
    ->and($scene->fieldState->renders)->toBe($renders)
    ->and([$outcome->completed, $outcome->failed, $scene->camera->stops, $battle->camera->stops])->toBe([0, 1, 1, 1])
    ->and($this->game->sceneManager->saveManager->writes)->toBe(0)
    ->and(new ReflectionProperty(SceneManager::class, 'sceneBeforeBattle')->getValue($this->game->sceneManager))->toBeNull();
})->with([false, true])->with(['quit', 'defeat', 'title']);

it('abandons real retained callers through Pause confirmation once even if a failure callback throws', function (bool $cinematic, string $destination, bool $throw) {
  [$scene, $battle, $session, $outcome] = startTeardownBattle($this->game, $this->logDirectory, $cinematic);
  if ($throw) { $outcome->onFailure = fn() => throw new RuntimeException('pause abandonment failure'); }
  ConfigStore::put(\Ichiloto\Engine\Util\Config\PlaySettings::class,
    new \Ichiloto\Engine\Util\Config\PlaySettings(['width' => 135, 'height' => 36]));
  Console::syncDimensions(135, 36);
  InputManager::setInputSource(new class implements \Ichiloto\Engine\IO\InputSources\InputSourceInterface {
    public function poll(): ?\Ichiloto\Engine\IO\Enumerations\KeyCode { return null; }
    public function reset(bool $drainBufferedInput = false): void {}
  });
  InputManager::setBindings(['confirm' => ['keys' => [\Ichiloto\Engine\IO\Enumerations\KeyCode::ENTER]]]);
  $pause = new class(new SceneStateContext($battle)) extends \Ichiloto\Engine\Scenes\Battle\States\BattlePauseState {
    public float $now = 0;
    protected function createMenu(): \Ichiloto\Engine\Battle\Presentation\BattlePauseMenu
    {
      return new \Ichiloto\Engine\Battle\Presentation\BattlePauseMenu(clock: fn(): float => $this->now);
    }
  };
  new ReflectionProperty(BattleScene::class, 'pauseState')->setValue($battle, $pause);
  $battle->setState($pause);
  $pause->now = 0.2; $pause->execute();
  $pause->menu->navigate($destination === 'title' ? 2 : 3);
  new ReflectionProperty(InputManager::class, 'keyPress')->setValue(null, \Ichiloto\Engine\IO\Enumerations\KeyCode::ENTER);
  $pause->execute();
  $pause->now += 0.08; $pause->execute(); $pause->now += 0.08; $pause->execute();
  expect($pause->menu->selection)->toBe(0)->and($session->status)->toBe(EventExecutionStatus::SUSPENDED);
  $pause->menu->navigate(1);
  new ReflectionProperty(InputManager::class, 'keyPress')->setValue(null, \Ichiloto\Engine\IO\Enumerations\KeyCode::ENTER);
  $pause->execute();
  $oldMenu = $pause->menu;
  $renders = $scene->fieldState->renders;
  $pause->now += 0.13; $pause->execute();
  $pause->now += 1; $pause->execute();
  expect($session->status)->toBe(EventExecutionStatus::FAILED)
    ->and([$outcome->completed, $outcome->failed])->toBe([0, 1])
    ->and($scene->cinematicStage->all())->toBe([])
    ->and($scene->npcManager->findById('guide')->position->x)->toBe($cinematic ? 7.0 : 8.0)
    ->and($scene->fieldState->renders)->toBe($renders)
    ->and($this->game->sceneManager->saveManager->writes)->toBe(0)
    ->and($pause->menu)->toBeNull()->and($oldMenu->tick())->toBeNull()
    ->and(new ReflectionProperty(SceneManager::class, 'sceneBeforeBattle')->getValue($this->game->sceneManager))->toBeNull();
  if ($destination === 'title') { expect($this->game->sceneManager->currentScene)->toBeInstanceOf(TitleScene::class); }
  else { expect($this->game->hasStopped())->toBeTrue(); }
})->with([false, true])->with(['title', 'quit'])->with([false, true]);

it('completes an ordinary battle through the real end state without invoking an unused Pause cleanup', function () {
  [$scene, $battle, $session, $outcome] = startTeardownBattle($this->game, $this->logDirectory, false);
  $engine = $this->createMock(\Ichiloto\Engine\Battle\Interfaces\BattleEngineInterface::class);
  $engine->expects($this->once())->method('stop');
  new ReflectionProperty(Game::class, 'engine')->setValue($this->game, $engine);
  $pause = new class(new SceneStateContext($battle)) extends \Ichiloto\Engine\Scenes\Battle\States\BattlePauseState {
    public function exit(): void { throw new RuntimeException('Unused Pause cleanup must not run.'); }
  };
  new ReflectionProperty(BattleScene::class, 'pauseState')->setValue($battle, $pause);
  $battle->result = new BattleResult('Victory');
  $battle->setState(new \Ichiloto\Engine\Scenes\Battle\States\BattleEndState(new SceneStateContext($battle)));
  $scene->eventInterpreter->update(1);
  expect($pause->hasOwnedResources())->toBeFalse()
    ->and($this->game->sceneManager->currentScene)->toBe($scene)
    ->and($scene->gameState->getVariable('battle-result'))->toBe('victory')
    ->and([$outcome->completed, $outcome->failed])->toBe([1, 0])
    ->and($session->status)->toBe(EventExecutionStatus::COMPLETED);
});
