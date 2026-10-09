<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
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
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Events\MovementEvent;
use Ichiloto\Engine\Field\EncounterManager;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PlayerWalk;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\KeyTransition;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldMetric;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Game\States\GameSceneState;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Tests\Support\Input\FakeHeldInputSource;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeHeldInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

/** The field metric's step: one 48-pixel cell, across or down, at RPG Maker's 180 field pixels per second. */
const WALK_STEP = 16 / 60;

/** Drives PlayerWalk the way FieldState does: one input update, then one walk update per frame. */
final class PlayerWalkDriver
{
  public float $time = 0.0;
  /** @var list<array{float, int, int, float}> Committed steps: time, dx, dy, seconds. */
  public array $steps = [];
  /** @var list<array{float, int, int}> Every attempt, including blocked ones. */
  public array $attempts = [];
  public ?Closure $blocks = null;
  public ?Closure $onStep = null;
  public readonly PlayerWalk $walk;

  public function __construct(public readonly FakeHeldInputSource $source, ?FieldMetric $metric = null)
  {
    $this->walk = new PlayerWalk($metric ?? new FieldMetric());
  }

  /** @param float|list<float> $frames One frame time, or a cycle of them. */
  public function run(float $seconds, float|array $frames = 1 / 60): void
  {
    $frames = (array)$frames;
    $end = $this->time + $seconds - 1e-9;
    for ($index = 0; $this->time < $end; $index++) {
      $this->frame($frames[$index % count($frames)]);
    }
  }

  public function frame(float $delta = 1 / 60): void
  {
    InputManager::handleInput();
    $this->walk->update($delta, function (Vector2 $direction, float $seconds): bool {
      $this->attempts[] = [$this->time, (int)$direction->x, (int)$direction->y];
      if ($this->blocks !== null && ($this->blocks)($this->time)) {
        return false;
      }
      $this->steps[] = [$this->time, (int)$direction->x, (int)$direction->y, $seconds];
      ($this->onStep)?->__invoke();
      return true;
    });
    $this->time += $delta;
  }

  /** @return list<string> Directions of committed steps, in order. */
  public function getDirections(): array
  {
    return array_map(static fn(array $step): string => match ([$step[1], $step[2]]) {
      [0, -1] => 'up', [0, 1] => 'down', [-1, 0] => 'left', [1, 0] => 'right',
    }, $this->steps);
  }

  /** @return list<int> Frame numbers (at 60 per second) of committed steps. */
  public function getFrames(): array
  {
    return array_map(static fn(array $step): int => (int)round($step[0] * 60), $this->steps);
  }
}

beforeEach(function () {
  $this->oldSource = InputManager::getInputSource();
  $this->oldBindings = InputManager::getBindings();
  $this->oldEventManager = new ReflectionProperty(InputManager::class, 'eventManager')->getValue();
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
  InputManager::setBindings([
    'up' => ['keys' => [KeyCode::UP]], 'down' => ['keys' => [KeyCode::DOWN, KeyCode::s]],
    'left' => ['keys' => [KeyCode::LEFT]], 'right' => ['keys' => [KeyCode::RIGHT]],
  ]);
  $this->source = new FakeHeldInputSource();
  InputManager::setInputSource($this->source);
  $this->driver = new PlayerWalkDriver($this->source);
});

afterEach(function () {
  InputManager::setInputSource($this->oldSource);
  new ReflectionProperty(InputManager::class, 'config')->setValue(null, $this->oldBindings);
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, $this->oldEventManager);
});

it('walks while held, switches to the latest direction at the next step and resumes the one still held', function () {
  $this->source->press('down', KeyCode::DOWN);
  $this->driver->run(40 / 60);
  // A press from standing faces and steps at once; each vertical step takes 16 frames.
  expect($this->driver->getFrames())->toBe([0, 16, 32]);
  $this->source->press('right', KeyCode::RIGHT);
  $this->driver->run(26 / 60);
  // Right waits for the step in progress: no free step at frame 40. Sideways steps take 16 frames too.
  expect($this->driver->getFrames())->toBe([0, 16, 32, 48, 64])
    ->and($this->driver->getDirections())->toBe(['down', 'down', 'down', 'right', 'right']);
  $this->source->release('right');
  $this->driver->run(40 / 60);
  expect(array_slice($this->driver->getFrames(), 5))->toBe([80, 96])
    ->and(array_slice($this->driver->getDirections(), 5))->toBe(['down', 'down']);
  $this->source->release('down');
  $this->driver->run(1.0);
  expect($this->driver->steps)->toHaveCount(7)
    ->and(array_column($this->driver->steps, 3))->toBe(array_fill(0, 7, WALK_STEP));
});

it('resolves opposing directions by recency and keeps an action held through another binding', function () {
  $this->source->press('down', KeyCode::DOWN);
  $this->driver->frame();
  $this->source->press('up', KeyCode::UP);
  $this->driver->run(20 / 60);
  expect($this->driver->getDirections())->toBe(['down', 'up']);
  $this->source->release('up');
  $this->driver->run(16 / 60);
  expect($this->driver->getDirections())->toBe(['down', 'up', 'down']);
  $this->source->press('s', KeyCode::s)->release('down');
  $this->driver->run(32 / 60);
  expect($this->driver->getDirections())->toBe(['down', 'up', 'down', 'down', 'down']);
});

it('counts OS repeats as neither presses nor extra movement', function () {
  $this->source->press('down', KeyCode::DOWN);
  foreach (range(1, 32) as $_) {
    // The event-only stream keeps delivering repeats; held state is unchanged by them.
    $this->source->keys[] = KeyCode::DOWN;
    $this->driver->frame();
  }
  expect($this->driver->getFrames())->toBe([0, 16]);
});

it('keeps quick taps without letting them outrun walking', function () {
  $this->source->press('right', KeyCode::RIGHT)->release('right');
  $this->driver->run(1.0);
  expect($this->driver->getFrames())->toBe([0]);
  // Two taps faster than a step: the second waits for the first to finish.
  $this->driver = new PlayerWalkDriver($this->source);
  $this->source->press('right', KeyCode::RIGHT)->release('right');
  $this->driver->frame();
  $this->driver->frame();
  $this->source->press('right', KeyCode::RIGHT)->release('right');
  $this->driver->run(1.0);
  expect($this->driver->getFrames())->toBe([0, 16]);
  // A tap during a held walk is taken at the next step, then the held key resumes.
  $this->driver = new PlayerWalkDriver($this->source);
  $this->source->press('down', KeyCode::DOWN);
  $this->driver->run(4 / 60);
  $this->source->press('left', KeyCode::LEFT)->release('left');
  $this->driver->run(52 / 60);
  expect($this->driver->getDirections())->toBe(['down', 'left', 'down', 'down'])
    ->and($this->driver->getFrames())->toBe([0, 16, 32, 48]);
});

it('discards a queued walking tap and its clock at an input reset boundary', function (string $boundary) {
  $this->source->press('down', KeyCode::DOWN);
  $this->driver->frame();
  $this->source->release('down')->press('right', KeyCode::RIGHT)->release('right');
  $this->driver->frame();
  expect($this->driver->getDirections())->toBe(['down']);

  match ($boundary) {
    'focus reset' => $this->source->transitions[] = KeyTransition::reset(),
    'manager reset' => InputManager::resetState(),
    'draining reset' => InputManager::resetState(true),
    'source replacement' => InputManager::setInputSource(new FakeHeldInputSource()),
  };
  $this->driver->run(1.0);
  expect($this->driver->getDirections())->toBe(['down']);

  InputManager::getInputSource()->press('left', KeyCode::LEFT);
  $this->driver->frame();
  expect($this->driver->getDirections())->toBe(['down', 'left']);
})->with(['focus reset', 'manager reset', 'draining reset', 'source replacement']);

it('accepts a fresh press after a reset in the same update without reviving an earlier tap', function () {
  $this->source->press('down', KeyCode::DOWN);
  $this->driver->frame();
  $this->source->release('down')->press('right', KeyCode::RIGHT)->release('right');
  $this->driver->frame();

  $this->source->transitions[] = KeyTransition::reset();
  $this->source->press('left', KeyCode::LEFT);
  $this->driver->frame();
  expect($this->driver->getDirections())->toBe(['down', 'left'])
    ->and($this->driver->getFrames())->toBe([0, 2]);
  $this->source->release('left');
  $this->driver->run(1.0);
  expect($this->driver->getDirections())->toBe(['down', 'left']);
});

it('discards held directions and a queued tap on renderer restart and walks only from new-session presses', function () {
  $consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  $transport = new FakeRendererTransport();
  $transport->onStart = static function (FakeRendererTransport $peer): void {
    $peer->batches = [[RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["key_transitions"]}')]];
  };
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), sys_get_temp_dir(),
    requiredCapabilities: [RendererSessionConfig::KEY_TRANSITIONS]), $transport);
  $frame = function (array $events) use ($transport, $runtime): void {
    $transport->batches[] = array_map(RendererEvent::fromJson(...), $events);
    $runtime->pump();
    $this->driver->frame();
  };
  try {
    $runtime->start('Walking lifecycle fixture', 12, 4);
    $frame(['{"protocol":2,"type":"key","key":"down","control":"down","repeat":false}']);
    $frame(['{"protocol":2,"type":"key","key":"right","control":"right","repeat":false}',
      '{"protocol":2,"type":"key_release","control":"right"}']);
    expect($this->driver->getDirections())->toBe(['down']);

    $runtime->restart();
    $this->driver->run(1.0);
    expect($this->driver->getDirections())->toBe(['down'])
      ->and(InputManager::isButtonHeld('down'))->toBeFalse()
      ->and(InputManager::isButtonHeld('right'))->toBeFalse();
    $frame(['{"protocol":2,"type":"key","key":"left","control":"left","repeat":false}']);
    expect($this->driver->getDirections())->toBe(['down', 'left']);
    $runtime->shutdown();
    $this->driver->run(1.0);
    expect($this->driver->getDirections())->toBe(['down', 'left'])
      ->and(InputManager::isButtonHeld('left'))->toBeFalse();
  } finally {
    $runtime->shutdown();
    foreach ($consoleState as $name => $value) { new ReflectionProperty(Console::class, $name)->setValue(null, $value); }
  }
});

it('walks equal distances in equal time at any update rate within one step', function (float|array $frames) {
  foreach (['right' => [KeyCode::RIGHT, WALK_STEP], 'down' => [KeyCode::DOWN, WALK_STEP]] as $control => [$key, $step]) {
    $source = new FakeHeldInputSource();
    InputManager::setInputSource($source);
    $driver = new PlayerWalkDriver($source);
    $source->press($control, $key);
    $driver->run(3.0, $frames);
    // The documented quantization bound: the first step is immediate, then one per
    // step duration, give or take the one step an update boundary can shift.
    expect(abs(count($driver->steps) - (floor(3.0 / $step) + 1)))->toBeLessThanOrEqual(1);
  }
})->with([
  '60 per second' => [1 / 60],
  '30 per second' => [1 / 30],
  '144 per second' => [1 / 144],
  '50 per second' => [1 / 50],
  'irregular' => [[0.004, 0.021, 0.017, 0.033, 0.009, 0.016]],
]);

it('walks both axes at one cell rate on the square field, from the metric alone', function () {
  $walked = static function (FieldMetric $metric, string $control, KeyCode $key, float $seconds): int {
    $source = new FakeHeldInputSource();
    InputManager::setInputSource($source);
    $driver = new PlayerWalkDriver($source, $metric);
    $source->press($control, $key);
    $driver->run($seconds);
    return count($driver->steps);
  };
  $field = new FieldMetric();
  $sideways = $walked($field, 'right', KeyCode::RIGHT, 4.0);
  // RPG Maker's 180 field pixels per second over 48-pixel cells: one step every 16/60 s on either axis.
  expect($field->getWalkSeconds(Vector2::right()))->toBe(WALK_STEP)
    ->and($field->getWalkSeconds(Vector2::down()))->toBe(WALK_STEP)
    ->and($walked($field, 'down', KeyCode::DOWN, 4.0))->toBe($sideways)
    ->and(abs($sideways - (floor(4.0 / WALK_STEP) + 1)))->toBeLessThanOrEqual(1);
  // The pace follows the metric's cell size, never an axis.
  $small = new FieldMetric(24);
  expect($walked($small, 'right', KeyCode::RIGHT, 4.0))->toBe($walked($small, 'down', KeyCode::DOWN, 4.0))
    ->and($walked($small, 'down', KeyCode::DOWN, 4.0))->toBeGreaterThan($sideways * 1.9);
});

it('banks no burst while blocked and catches up at most one step after a stall', function () {
  $this->driver->blocks = static fn(float $time): bool => $time < 1.0 - 1e-6;
  $this->source->press('right', KeyCode::RIGHT);
  $this->driver->run(1.0 + 36 / 60);
  // Blocked attempts face the wall each update; once clear, walking resumes at its pace.
  expect(count($this->driver->attempts))->toBeGreaterThan(55)
    ->and($this->driver->getFrames())->toBe([60, 76, 92]);

  $this->driver = new PlayerWalkDriver($this->source);
  $this->source->press('down', KeyCode::DOWN);
  $this->driver->frame();
  $this->driver->frame(2.0);
  $this->driver->run(20 / 60);
  // Two seconds of stall owe seven steps: the late update takes one, the next
  // catches up one more, and walking then keeps its pace.
  expect($this->driver->getFrames())->toBe([0, 1, 121, 137]);
});

it('cancels walking whenever the field missed an update and treats earlier presses as stale', function () {
  $this->source->press('down', KeyCode::DOWN);
  $this->driver->run(20 / 60);
  expect($this->driver->steps)->toHaveCount(2);
  // A menu or dialogue owned three input updates; Down stayed held throughout.
  foreach (range(1, 3) as $_) { InputManager::handleInput(); }
  $this->driver->run(1.0);
  expect($this->driver->steps)->toHaveCount(2);
  $this->source->release('down');
  $this->driver->frame();
  $this->source->press('down', KeyCode::DOWN);
  $this->driver->frame();
  expect($this->driver->steps)->toHaveCount(3);
  // A step that hands control elsewhere (a transfer) cancels from inside the step.
  $this->driver->onStep = fn() => $this->driver->walk->cancel();
  $this->driver->run(1.0);
  expect($this->driver->steps)->toHaveCount(4);
  $this->driver->onStep = null;
  $this->source->release('down')->press('down', KeyCode::DOWN);
  $this->driver->frame();
  expect($this->driver->steps)->toHaveCount(5);
});

/** A field whose Player records the validated moves it is asked to make. */
final class WalkingFieldScene extends GameScene
{
  public function __construct()
  {
    $this->camera = new class extends Camera { public function __construct() {} };
    $scene = $this;
    $this->player = new class($scene) extends Player {
      /** @var list<array{int, int, float}> */
      public array $moves = [];
      public function __construct(public GameScene $owner) { $this->position = new Vector2(5, 5); }
      public function tryMove(Vector2 $direction, Camera $camera): bool
      {
        $this->moves[] = [(int)$direction->x, (int)$direction->y, $this->owner->getStepSeconds($direction)];
        $this->position->add($direction);
        return true;
      }
    };
  }
}

final class WalkingFieldState extends FieldState
{
  public function navigate(GameScene $scene): void { $this->handleNavigation($scene); }
  // These tests exercise state ownership and movement, not HUD or screen setup.
  public function enter(): void {}
  public function exit(): void {}
}

it('invalidates every held direction and pending tap across immediate menu entry and return', function () {
  $scene = new WalkingFieldScene();
  $context = new SceneStateContext($scene);
  $field = new WalkingFieldState($context);
  new ReflectionProperty(GameScene::class, 'fieldState')->setValue($scene, $field);
  $menu = new class($context) extends GameSceneState {
    public function execute(?SceneStateContext $context = null): void {}
  };
  $delta = new ReflectionProperty(Time::class, 'deltaTime');
  $previousDelta = $delta->getValue();
  try {
    $delta->setValue(null, 1 / 60);
    $this->source->press('down', KeyCode::DOWN)->press('s', KeyCode::s);
    InputManager::handleInput();
    $field->navigate($scene);
    $this->source->press('right', KeyCode::RIGHT)->release('right');
    InputManager::handleInput();
    $field->navigate($scene);

    // No input-update gap separates the state change from its return.
    $scene->setState($menu);
    $this->source->press('up', KeyCode::UP);
    InputManager::handleInput();
    $scene->setState($field);
    $field->navigate($scene);
    foreach (range(1, 60) as $_) { InputManager::handleInput(); $field->navigate($scene); }
    expect($scene->player->moves)->toBe([[0, 1, WALK_STEP]]);

    // Releasing the newest stale key must not revive either older Down binding.
    $this->source->release('up');
    foreach (range(1, 20) as $_) { InputManager::handleInput(); $field->navigate($scene); }
    expect($scene->player->moves)->toHaveCount(1);
    $this->source->release('s')->press('s', KeyCode::s);
    InputManager::handleInput();
    $field->navigate($scene);
    expect($scene->player->moves)->toBe([[0, 1, WALK_STEP], [0, 1, WALK_STEP]]);
  } finally {
    $delta->setValue(null, $previousDelta);
  }
});

it('does not resume pre-context or during-context walking presses after a yielded dialogue or cinematic', function (string $contextKind) {
  $scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()
    ->onlyMethods(['reconcileFieldPresentation', 'onEventSessionFinished'])->getMock();
  $scene->expects($this->once())->method('onEventSessionFinished')->with($this->isInstanceOf(EventExecutionSession::class), true);
  $manager = $this->getMockBuilder(SceneManager::class)->disableOriginalConstructor()->onlyMethods(['hasSceneTransition'])->getMock();
  $manager->method('hasSceneTransition')->willReturn(false);
  new ReflectionProperty(SceneManager::class, 'currentScene')->setValue($manager, $scene);
  new ReflectionProperty(GameScene::class, 'sceneManager')->setValue($scene, $manager);
  new ReflectionProperty(GameScene::class, 'gameState')->setValue($scene, new GameState());
  new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, new class extends Camera { public function __construct() {} });
  $player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()->onlyMethods(['tryMove', 'refreshTalkTarget'])->getMock();
  new ReflectionProperty(Player::class, 'position')->setValue($player, new Vector2(1, 1));
  $moves = [];
  $player->method('tryMove')->willReturnCallback(static function (Vector2 $direction, Camera $camera) use (&$moves): bool {
    $moves[] = [(int)$direction->x, (int)$direction->y];
    return true;
  });
  new ReflectionProperty(GameScene::class, 'player')->setValue($scene, $player);
  $field = $this->getMockBuilder(FieldState::class)->setConstructorArgs([new SceneStateContext($scene)])
    ->onlyMethods(['renderPresentationOverlay'])->getMock();
  new ReflectionProperty(GameScene::class, 'state')->setValue($scene, $field);
  new ReflectionProperty(GameScene::class, 'fieldState')->setValue($scene, $field);
  $dialogueComplete = false;
  $presentation = $this->createMock(EventPresentationInterface::class);
  // The mutable completion flag models the dialogue finishing on a later field tick.
  $presentation->method('isComplete')->willReturnCallback(static function () use (&$dialogueComplete): bool { return $dialogueComplete; });
  $interpreter = new EventInterpreter($scene, $presentation);
  new ReflectionProperty(GameScene::class, 'eventInterpreter')->setValue($scene, $interpreter);
  $frame = static function (int $count = 1) use ($field): void {
    foreach (range(1, $count) as $_) { InputManager::handleInput(); $field->execute(); }
  };
  $delta = new ReflectionProperty(Time::class, 'deltaTime');
  $previousDelta = $delta->getValue();
  try {
    $delta->setValue(null, 1 / 60);
    $this->source->press('down', KeyCode::DOWN);
    $frame();
    $this->source->press('right', KeyCode::RIGHT)->release('right');
    $frame();
    expect($moves)->toBe([[0, 1]]);

    $session = $contextKind === 'dialogue'
      ? $interpreter->run([['type' => 'text', 'text' => 'Synthetic dialogue.']])
      : $interpreter->runCinematic(CinematicDefinition::fromArrays(
        ['id' => 'walking-lifecycle', 'name' => 'Synthetic walking lifecycle'],
        [['type' => 'wait', 'seconds' => 0.1]],
      ));
    expect($session->status)->toBe(EventExecutionStatus::YIELDED)
      ->and($scene->hasUnstableEventSession())->toBeTrue();
    $this->source->press('up', KeyCode::UP);
    $frame(3);
    expect($moves)->toBe([[0, 1]])->and($scene->hasUnstableEventSession())->toBeTrue();
    $dialogueComplete = true;
    $frame(60);
    expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($scene->hasUnstableEventSession())->toBeFalse()->and($moves)->toBe([[0, 1]]);

    $this->source->release('up');
    $frame(20);
    expect($moves)->toBe([[0, 1]]);
    $this->source->release('down')->press('down', KeyCode::DOWN);
    $frame();
    expect($moves)->toBe([[0, 1], [0, 1]]);
  } finally {
    $delta->setValue(null, $previousDelta);
  }
})->with(['dialogue', 'cinematic']);

it('keeps held walking cardinal at a blocked corner and applies effects once per committed cell', function (CollisionType $obstacle) {
  $configState = new ReflectionProperty(ConfigStore::class, 'store')->getValue();
  $eventState = new ReflectionProperty(EventManager::class, 'instance')->getValue();
  $delta = new ReflectionProperty(Time::class, 'deltaTime');
  $previousDelta = $delta->getValue();
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub());
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  try {
    $game = new class extends Game {
      public function __construct() {}
      public function __destruct() {}
    };
    $scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()->onlyMethods(['getGame'])->getMock();
    $scene->method('getGame')->willReturn($game);
    $camera = new class extends Camera { public function __construct() {} };
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
    $map = $this->getMockBuilder(MapManager::class)->disableOriginalConstructor()->onlyMethods(['scrollMap'])->getMock();
    $map->method('scrollMap')->willReturn(false);
    new ReflectionProperty(MapManager::class, 'gameScene')->setValue($map, $scene);
    $collision = array_fill(0, 4, array_fill(0, 4, CollisionType::NONE->value));
    $collision[1][2] = $collision[2][1] = $obstacle->value;
    new ReflectionProperty(MapManager::class, 'collisionMap')->setValue($map, $collision);
    new ReflectionProperty(GameScene::class, 'mapManager')->setValue($scene, $map);
    $player = new class($scene) extends Player {
      public array $triggerSteps = [];
      public function __construct(GameScene $scene) {
        parent::__construct($scene, 'Synthetic walker', new Vector2(1, 1), new Rect(0, 0, 1, 1), ['@']);
      }
      public function render(): void {}
      public function renderEventCues(): void {}
      public function erasePlayer(Camera $camera, ?array $sprite = null): void {}
      protected function renderLocationHUDWindow(): void {}
      protected function handleTriggers(MovementEvent $event): void {
        $this->triggerSteps[] = [$event->destination->x, $event->destination->y];
        parent::handleTriggers($event);
      }
    };
    new ReflectionProperty(GameScene::class, 'player')->setValue($scene, $player);
    $encounters = new class($scene) extends EncounterManager {
      public array $steps = [];
      public function registerStep(?CollisionType $tile): void { $this->steps[] = $tile; }
    };
    new ReflectionProperty(GameScene::class, 'encounterManager')->setValue($scene, $encounters);
    $observer = new class implements ObserverInterface {
      public array $steps = [];
      public function onNotify(object $entity, EventInterface $event): void {
        $this->steps[] = [$event->origin->x, $event->origin->y, $event->destination->x, $event->destination->y];
      }
    };
    $player->addObserver($observer);
    $field = new WalkingFieldState(new SceneStateContext($scene));
    $frame = static function (int $count = 1) use ($field, $scene): void {
      foreach (range(1, $count) as $_) { InputManager::handleInput(); $field->navigate($scene); }
    };
    $delta->setValue(null, 1 / 60);

    // The diagonal destination is open, but both cardinal neighbours block.
    $this->source->press('down', KeyCode::DOWN)->press('right', KeyCode::RIGHT);
    $frame(20);
    expect([$player->position->x, $player->position->y])->toBe([1.0, 1.0])
      ->and($player->heading)->toBe(MovementHeading::EAST)
      ->and($player->triggerSteps)->toBe([])->and($encounters->steps)->toBe([])->and($observer->steps)->toBe([]);
    $this->source->release('right');
    $frame(20);
    expect([$player->position->x, $player->position->y])->toBe([1.0, 1.0])
      ->and($player->heading)->toBe(MovementHeading::SOUTH)
      ->and($player->triggerSteps)->toBe([])->and($encounters->steps)->toBe([])->and($observer->steps)->toBe([]);

    $this->source->press('left', KeyCode::LEFT);
    $frame();
    $this->source->release('left');
    $frame(16);
    $this->source->release('down');
    $frame(32);
    $player->completeArrival();
    $player->completeArrival();
    expect([$player->position->x, $player->position->y])->toBe([0.0, 2.0])
      ->and($player->triggerSteps)->toBe([[0.0, 1.0], [0.0, 2.0]])
      ->and($encounters->steps)->toBe([CollisionType::NONE, CollisionType::NONE])
      ->and($observer->steps)->toBe([[1.0, 1.0, 0.0, 1.0], [0.0, 1.0, 0.0, 2.0]]);
  } finally {
    $delta->setValue(null, $previousDelta);
    new ReflectionProperty(ConfigStore::class, 'store')->setValue(null, $configState);
    new ReflectionProperty(EventManager::class, 'instance')->setValue(null, $eventState);
  }
})->with(['wall' => [CollisionType::SOLID], 'NPC occupancy' => [CollisionType::NPC], 'counter' => [CollisionType::COUNTER]]);

it('cancels held walking when a scripted event takes control even without missing a field update', function () {
  $scene = new WalkingFieldScene();
  $state = new WalkingFieldState(new SceneStateContext($scene));
  new ReflectionProperty(GameScene::class, 'fieldState')->setValue($scene, $state);
  $delta = new ReflectionProperty(Time::class, 'deltaTime');
  $previousDelta = $delta->getValue();
  try {
    $delta->setValue(null, 1 / 60);
    $this->source->press('down', KeyCode::DOWN);
    InputManager::handleInput();
    $state->navigate($scene);
    expect($scene->player->moves)->toHaveCount(1);

    // An immediate event can finish before the next input update. Input-update
    // gaps therefore cannot stand in for the event's ownership boundary.
    $session = new EventExecutionSession([]);
    $scene->onEventSessionStarted($session);
    $session->complete();
    foreach (range(1, 60) as $_) { InputManager::handleInput(); $state->navigate($scene); }
    expect($scene->player->moves)->toHaveCount(1);

    $this->source->release('down');
    InputManager::handleInput();
    $state->navigate($scene);
    $this->source->press('down', KeyCode::DOWN);
    InputManager::handleInput();
    $state->navigate($scene);
    expect($scene->player->moves)->toHaveCount(2);
  } finally {
    $delta->setValue(null, $previousDelta);
  }
});

it('walks the field through the ordinary validated move with held input and steps per key event without it', function () {
  $scene = new WalkingFieldScene();
  $state = new WalkingFieldState(new SceneStateContext($scene));
  $delta = new ReflectionProperty(Time::class, 'deltaTime');
  $previousDelta = $delta->getValue();
  try {
    $delta->setValue(null, 1 / 60);
    $this->source->press('down', KeyCode::DOWN);
    foreach (range(1, 20) as $_) { InputManager::handleInput(); $state->navigate($scene); }
    // Each step is one validated move, presented over the walking time of its axis.
    expect($scene->player->moves)->toBe([[0, 1, WALK_STEP], [0, 1, WALK_STEP]])
      ->and($scene->getStepSeconds(Vector2::right()))->toBe(WALK_STEP);
    $scene->player->moves = [];
    // Event-only input keeps its one step per key event, whatever the timing.
    InputManager::setInputSource(new FakeInputSource(KeyCode::DOWN, null, KeyCode::DOWN, KeyCode::RIGHT, null));
    foreach (range(1, 5) as $_) { InputManager::handleInput(); $state->navigate($scene); }
    expect(array_map(static fn(array $move): array => [$move[0], $move[1]], $scene->player->moves))
      ->toBe([[0, 1], [0, 1], [1, 0]]);
  } finally {
    $delta->setValue(null, $previousDelta);
  }
});
