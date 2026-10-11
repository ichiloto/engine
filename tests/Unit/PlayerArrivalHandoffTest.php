<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Events\MovementEvent;
use Ichiloto\Engine\Field\EncounterManager;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PreparedMap;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContext;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Game\States\GameSceneState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeHeldInputSource;
use Tests\Support\Input\FakeInputSource;
use function Tests\Support\Rendering\characterSheetData;
use function Tests\Support\Rendering\writeCharacterSheetPng;

require_once __DIR__ . '/../Support/Input/FakeHeldInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

final class ArrivalHandoffPlayer extends Player
{
  public array $triggers = [];
  public ?Closure $onTrigger = null;
  public function render(): void {}
  public function renderEventCues(): void {}
  public function erasePlayer(Camera $camera, ?array $sprite = null): void {}
  protected function renderLocationHUDWindow(): void {}
  protected function handleTriggers(MovementEvent $event): void
  {
    $this->triggers[] = [$event->destination->x, $event->destination->y];
    parent::handleTriggers($event);
    ($this->onTrigger)?->__invoke();
  }
}

final class ArrivalHandoffField extends FieldState
{
  public function navigate(GameScene $scene): void { $this->handleNavigation($scene); }
  public function enter(): void {}
  public function resume(): void {}
  public function renderTheField(bool $forceFullRepaint = false): void {}
}

beforeEach(function () {
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  $this->configState = new ReflectionProperty(ConfigStore::class, 'store')->getValue();
  $this->eventState = new ReflectionProperty(EventManager::class, 'instance')->getValue();
  $this->inputState = new ReflectionClass(InputManager::class)->getStaticProperties();
  $this->debugState = new ReflectionClass(Debug::class)->getStaticProperties();
  $this->previousDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub());
  Console::setTerminalOutputEnabled(false);
  InputManager::setBindings(['up' => ['keys' => [KeyCode::UP]], 'right' => ['keys' => [KeyCode::RIGHT]]]);
  $this->source = new FakeHeldInputSource();
  InputManager::setInputSource($this->source);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 1 / 60);

  $game = new class extends Game {
    public function __construct() {}
    public function __destruct() {}
  };
  $this->scene = $this->getStubBuilder(GameScene::class)->disableOriginalConstructor()
    ->onlyMethods(['getGame', 'onEventSessionFinished', 'reconcileFieldPresentation'])->getStub();
  $this->scene->method('getGame')->willReturn($game);
  $this->manager = $this->getStubBuilder(SceneManager::class)->disableOriginalConstructor()
    ->onlyMethods(['hasSceneTransition'])->getStub();
  $this->transitionPending = false;
  $this->manager->method('hasSceneTransition')->willReturnCallback(fn(): bool => $this->transitionPending);
  new ReflectionProperty(SceneManager::class, 'currentScene')->setValue($this->manager, $this->scene);
  new ReflectionProperty(GameScene::class, 'sceneManager')->setValue($this->scene, $this->manager);
  new ReflectionProperty(GameScene::class, 'gameState')->setValue($this->scene, new GameState());
  $this->camera = new Camera($this->scene, 10, 10, worldSpace: array_fill(0, 10, str_repeat('.', 10)));
  new ReflectionProperty(GameScene::class, 'camera')->setValue($this->scene, $this->camera);
  $this->map = $this->getMockBuilder(MapManager::class)->disableOriginalConstructor()
    ->onlyMethods(['scrollMap', 'prepareMap', 'applyPreparedMap'])->getMock();
  $this->map->expects($this->atLeastOnce())->method('scrollMap')->willReturn(false);
  new ReflectionProperty(MapManager::class, 'gameScene')->setValue($this->map, $this->scene);
  new ReflectionProperty(MapManager::class, 'collisionMap')->setValue($this->map,
    array_fill(0, 10, array_fill(0, 10, CollisionType::ENCOUNTER->value)));
  new ReflectionProperty(MapManager::class, 'layers')->setValue($this->map,
    new MapLayerSet([new MapLayer('ground', 0, false, 'synthetic', implode("\n", array_fill(0, 10, str_repeat('.', 10))))]));
  new ReflectionProperty(GameScene::class, 'mapManager')->setValue($this->scene, $this->map);
  $this->scene->setPresentationContext(new ScenePresentationContext(new RendererGridConfig(10, 10, 48, 48),
    static fn(string $capability): bool => true, graphical: true));
  $this->fixtureRoot = $root = createTestDirectory('ichiloto-arrival-handoff-');
  Debug::configure(['log_directory' => $root . '/logs']);
  writeCharacterSheetPng($root . '/Graphics/Characters/Heroes.png');
  $this->player = new ArrivalHandoffPlayer($this->scene, 'Synthetic walker', new Vector2(3, 5), new Rect(0, 0, 1, 1),
    ['v'], graphicalSprites: CharacterSheet::fromArray(characterSheetData()), assetRoot: $root);
  new ReflectionProperty(GameScene::class, 'player')->setValue($this->scene, $this->player);
  $this->field = new ArrivalHandoffField(new SceneStateContext($this->scene));
  new ReflectionProperty(GameScene::class, 'fieldState')->setValue($this->scene, $this->field);
  new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, $this->field);
  $this->encounters = new class($this->scene) extends EncounterManager {
    public array $steps = [];
    public ?Closure $onStep = null;
    public function registerStep(?CollisionType $tile): void
    {
      $this->steps[] = $tile;
      ($this->onStep)?->__invoke();
    }
  };
  new ReflectionProperty(GameScene::class, 'encounterManager')->setValue($this->scene, $this->encounters);
  $this->observer = new class implements ObserverInterface {
    public array $steps = [];
    public ?Closure $onArrival = null;
    public function onNotify(object $entity, EventInterface $event): void
    {
      assert($event instanceof MovementEvent);
      $this->steps[] = [$event->origin->x, $event->origin->y, $event->destination->x, $event->destination->y];
      ($this->onArrival)?->__invoke();
    }
  };
  $this->player->addObserver($this->observer);
  $this->presentation = $this->createStub(EventPresentationInterface::class);
  $this->presentation->method('isComplete')->willReturn(false);
  $this->interpreter = new EventInterpreter($this->scene, $this->presentation);
  new ReflectionProperty(GameScene::class, 'eventInterpreter')->setValue($this->scene, $this->interpreter);
});

afterEach(function () {
  $this->interpreter->failActiveSession('Synthetic fixture teardown.');
  // Teardown may write its final diagnostic after the shared fixture cleanup.
  $GLOBALS['ichilotoTestDirectories'][$this->fixtureRoot] = $this->fixtureRoot;
  cleanUpTestDirectories();
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $this->previousDelta);
  new ReflectionProperty(ConfigStore::class, 'store')->setValue(null, $this->configState);
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, $this->eventState);
  foreach ([Console::class => $this->consoleState, InputManager::class => $this->inputState,
    Debug::class => $this->debugState] as $class => $state) {
    foreach ($state as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('rejects the next move when a deferred arrival hands off control without losing that arrival', function (string $handoff, string $producer) {
  $callback = function () use ($handoff): void {
    // Each arrival takes ownership only once, including reentrant route steps.
    $this->player->onTrigger = $this->encounters->onStep = $this->observer->onArrival = null;
    match ($handoff) {
      'dialogue' => $this->interpreter->run([['type' => 'text', 'text' => 'Synthetic dialogue']]),
      'cinematic' => $this->interpreter->runCinematic(CinematicDefinition::fromArrays(
        ['id' => 'arrival-handoff', 'name' => 'Synthetic handoff'], [['type' => 'wait', 'seconds' => 1.0]])),
      'immediate event' => $this->interpreter->run([['type' => 'wait', 'seconds' => 0]]),
      'cancel intent' => $this->field->cancelWalking(),
      'input reset' => InputManager::resetState(),
      'suspend' => $this->field->suspend(),
      'battle transition' => $this->transitionPending = true,
      'menu and return' => (function (): void {
        $menu = new class(new SceneStateContext($this->scene)) extends GameSceneState {
          public function execute(?SceneStateContext $context = null): void {}
        };
        $this->scene->setState($menu);
        $this->scene->setState($this->field);
      })(),
    };
  };
  match ($producer) {
    'trigger' => $this->player->onTrigger = $callback,
    'encounter' => $this->encounters->onStep = $callback,
    'observer' => $this->observer->onArrival = $callback,
  };
  $this->source->press('up', KeyCode::UP);
  InputManager::handleInput();
  expect($this->player->tryMove(Vector2::up(), $this->camera))->toBeTrue()
    ->and($this->observer->steps)->toBe([]);
  // The next paced move flushes the preceding slide's arrival synchronously.
  expect($this->player->tryMove(Vector2::right(), $this->camera))->toBeFalse()
    ->and([$this->player->position->x, $this->player->position->y])->toBe([3.0, 4.0]);
  $this->player->advanceGraphicalAnimation(2.0);
  $this->player->completeArrival();
  $this->player->completeArrival();
  expect($this->player->triggers)->toBe([[3.0, 4.0]])
    ->and($this->encounters->steps)->toBe([CollisionType::ENCOUNTER])
    ->and($this->observer->steps)->toBe([[3.0, 5.0, 3.0, 4.0]]);
})->with(['dialogue', 'cinematic', 'immediate event', 'cancel intent', 'input reset', 'suspend', 'battle transition', 'menu and return'])
  ->with(['trigger', 'encounter', 'observer']);

it('does not carry a deferred move onto a relocated field position', function () {
  $this->observer->onArrival = function (): void {
    $this->scene->relocatePlayer(new Vector2(6, 6));
  };
  expect($this->player->tryMove(Vector2::up(), $this->camera))->toBeTrue()
    ->and($this->player->tryMove(Vector2::right(), $this->camera))->toBeFalse()
    ->and([$this->player->position->x, $this->player->position->y])->toBe([6.0, 6.0])
    ->and($this->observer->steps)->toBe([[3.0, 5.0, 3.0, 4.0]]);
});

it('rechecks event ownership in a lightweight scene without a field controller', function () {
  new ReflectionProperty(GameScene::class, 'fieldState')->setValue($this->scene, null);
  $this->observer->onArrival = fn() => $this->interpreter->run([['type' => 'text', 'text' => 'Synthetic owner']]);
  expect($this->player->tryMove(Vector2::up(), $this->camera))->toBeTrue()
    ->and($this->player->tryMove(Vector2::right(), $this->camera))->toBeFalse()
    ->and([$this->player->position->x, $this->player->position->y])->toBe([3.0, 4.0])
    ->and($this->scene->hasUnstableEventSession())->toBeTrue()->and($this->observer->steps)->toHaveCount(1);
});

it('does not carry a deferred move into a newly loaded map', function () {
  $prepared = new PreparedMap([], [], [], [], []);
  $this->map->expects($this->once())->method('prepareMap')->with('synthetic-destination')->willReturn($prepared);
  $this->map->expects($this->once())->method('applyPreparedMap')->with($prepared, $this->player);
  $this->observer->onArrival = fn() => $this->scene->loadMap('synthetic-destination', $this->player);
  expect($this->player->tryMove(Vector2::up(), $this->camera))->toBeTrue()
    ->and($this->player->tryMove(Vector2::right(), $this->camera))->toBeFalse()
    ->and($this->scene->currentMapId)->toBe('synthetic-destination')
    ->and([$this->player->position->x, $this->player->position->y])->toBe([3.0, 4.0])
    ->and($this->observer->steps)->toHaveCount(1);
});

it('does not use the current frame input after an arrival event immediately returns control', function (bool $held) {
  expect($this->player->tryMove(Vector2::up(), $this->camera))->toBeTrue();
  $this->observer->onArrival = function (): void {
    $this->observer->onArrival = null;
    $this->interpreter->run([['type' => 'wait', 'seconds' => 0]]);
  };
  $this->player->advanceGraphicalAnimation(1.0);
  if ($held) { $this->source->press('right', KeyCode::RIGHT); }
  else { InputManager::setInputSource(new FakeInputSource(KeyCode::RIGHT, null, KeyCode::RIGHT)); }
  InputManager::handleInput();
  $this->field->execute();
  InputManager::handleInput();
  $this->field->execute();
  expect([$this->player->position->x, $this->player->position->y])->toBe([3.0, 4.0])
    ->and($this->observer->steps)->toHaveCount(1);
  if ($held) { $this->source->release('right')->press('right', KeyCode::RIGHT); }
  InputManager::handleInput();
  $this->field->execute();
  $this->player->advanceGraphicalAnimation(1.0);
  $this->player->completeArrival();
  expect([$this->player->position->x, $this->player->position->y])->toBe([4.0, 4.0])
    ->and($this->observer->steps)->toHaveCount(2);
})->with(['held input' => [true], 'event-only input' => [false]]);

it('cancels held intent at the arrival handoff and requires a fresh press without an input update gap', function () {
  $this->observer->onArrival = function (): void {
    $this->observer->onArrival = null;
    $session = $this->interpreter->run([['type' => 'wait', 'seconds' => 0]]);
    expect($session->status)->toBe(EventExecutionStatus::COMPLETED);
  };
  $this->source->press('up', KeyCode::UP);
  InputManager::handleInput();
  $this->field->navigate($this->scene);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $this->scene->getStepSeconds(Vector2::up()));
  InputManager::handleInput();
  $this->field->navigate($this->scene);
  foreach (range(1, 20) as $_) { InputManager::handleInput(); $this->field->navigate($this->scene); }
  $this->player->advanceGraphicalAnimation(2.0);
  $this->player->completeArrival();
  expect([$this->player->position->x, $this->player->position->y])->toBe([3.0, 4.0])
    ->and($this->observer->steps)->toHaveCount(1);
  $this->source->release('up')->press('up', KeyCode::UP);
  InputManager::handleInput();
  $this->field->navigate($this->scene);
  $this->player->advanceGraphicalAnimation(2.0);
  $this->player->completeArrival();
  expect([$this->player->position->x, $this->player->position->y])->toBe([3.0, 3.0])
    ->and($this->observer->steps)->toHaveCount(2);
});

it('allows scripted cinematic moves after ownership has already changed', function (bool $resetInput) {
  if ($resetInput) {
    $this->source->press('up', KeyCode::UP);
    InputManager::handleInput();
    $this->observer->onArrival = fn() => InputManager::resetState();
  }
  $session = $this->interpreter->runCinematic(CinematicDefinition::fromArrays(
    ['id' => 'arrival-owned-route', 'name' => 'Synthetic owned route'],
    [['type' => 'move_route', 'secondsPerStep' => 0.1, 'steps' => [['direction' => 'right', 'count' => 2]]]],
  ));
  expect($this->scene->hasUnstableEventSession())->toBeTrue();
  foreach (range(1, 10) as $_) {
    $this->scene->advanceFieldPresentation(0.1);
    $this->player->completeArrival();
    $this->interpreter->update(0.1);
  }
  expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and([$this->player->position->x, $this->player->position->y])->toBe([5.0, 5.0])
    ->and($this->player->triggers)->toBe([[4.0, 5.0], [5.0, 5.0]])
    ->and($this->encounters->steps)->toHaveCount(2)->and($this->observer->steps)->toHaveCount(2);
})->with(['ordinary arrivals' => [false], 'arrivals reset field input' => [true]]);

it('allows an arrival-owned reentrant cinematic route but rejects the superseded outer move', function () {
  $this->observer->onArrival = function (): void {
    $this->observer->onArrival = null;
    $this->interpreter->runCinematic(CinematicDefinition::fromArrays(
      ['id' => 'arrival-reentrant-route', 'name' => 'Synthetic reentrant route'],
      [['type' => 'move_route', 'secondsPerStep' => 0.1, 'steps' => [['direction' => 'right', 'count' => 2]]]],
    ));
    $this->interpreter->update(0.1);
  };
  expect($this->player->tryMove(Vector2::up(), $this->camera))->toBeTrue()
    ->and($this->player->tryMove(Vector2::up(), $this->camera))->toBeFalse()
    ->and([$this->player->position->x, $this->player->position->y])->toBe([4.0, 4.0]);
  foreach (range(1, 10) as $_) {
    $this->scene->advanceFieldPresentation(0.1);
    $this->player->completeArrival();
    $this->interpreter->update(0.1);
  }
  expect([$this->player->position->x, $this->player->position->y])->toBe([5.0, 4.0])
    ->and($this->player->triggers)->toBe([[3.0, 4.0], [4.0, 4.0], [5.0, 4.0]])
    ->and($this->encounters->steps)->toHaveCount(3)->and($this->observer->steps)->toHaveCount(3);
});

it('keeps instant terminal and reduced-motion committed steps and their arrivals exactly once', function (bool $reduced) {
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['accessibility' => ['reducedMotion' => $reduced]]));
  if (!$reduced) {
    $this->scene->setPresentationContext(new ScenePresentationContext(new RendererGridConfig(10, 10),
      static fn(string $capability): bool => true, graphical: false));
    InputManager::setInputSource(new FakeInputSource(KeyCode::UP, null, KeyCode::UP));
  } else {
    $this->source->press('up', KeyCode::UP);
  }
  $this->observer->onArrival = fn() => $this->field->cancelWalking();
  InputManager::handleInput();
  $this->field->navigate($this->scene);
  $this->player->completeArrival();
  expect([$this->player->position->x, $this->player->position->y])->toBe([3.0, 4.0])
    ->and($this->player->triggers)->toHaveCount(1)->and($this->encounters->steps)->toHaveCount(1)
    ->and($this->observer->steps)->toHaveCount(1);
  if (!$reduced) {
    InputManager::handleInput();
    $this->field->navigate($this->scene);
    InputManager::handleInput();
    $this->field->navigate($this->scene);
    expect([$this->player->position->x, $this->player->position->y])->toBe([3.0, 3.0])
      ->and($this->observer->steps)->toHaveCount(2);
  }
})->with([false, true]);
