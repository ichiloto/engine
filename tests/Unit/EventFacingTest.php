<?php

use Ichiloto\Engine\Field\Enumerations\StepOrigin;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Entities\Interfaces\ActionInterface;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Enumerations\MovementEventType;
use Ichiloto\Engine\Events\Enumerations\EventEngagement;
use Ichiloto\Engine\Events\Interfaces\AutomaticEventTriggerInterface;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Events\MovementEvent;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Events\Triggers\EventTriggerFactory;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Tests\Support\PassiveGame;

class FacingProbeTrigger extends EventTrigger
{
  public array $calls = [];
  public function configure(): void { $this->isReusable = $this->data->reusable ?? true; }
  public function enter(EventTriggerContextInterface $context): void
  {
    $this->calls[] = 'enter';
    $context->player->availableAction = new class($this) implements ActionInterface {
      public function __construct(public EventTrigger $owner) {}
      public function execute(ActionContextInterface $context): void {}
    };
  }
  public function stay(EventTriggerContextInterface $context): void { $this->calls[] = 'stay'; }
  public function exit(EventTriggerContextInterface $context): void
  { $this->calls[] = 'exit'; $context->player->availableAction = null; }
}

class FacingAutomaticProbe extends FacingProbeTrigger implements AutomaticEventTriggerInterface
{
  public bool $sessionIsActive = false;
  public function runsAutomatically(): bool { return true; }
}

class FacingProbePlayer extends Player
{
  public function render(): void {}
  public function erase(): void {}
  public function erasePlayer(Camera $camera, ?array $sprite = null): void {}
  protected function renderLocationHUDWindow(): void {}
  public function dispatchMovement(Vector2 $origin, Vector2 $destination): void
  {
    $this->position->x = $destination->x;
    $this->position->y = $destination->y;
    $this->handleTriggers(new MovementEvent(MovementEventType::PLAYER_MOVE, $origin, $destination));
  }
}

beforeEach(function () {
  $this->oldDebug = new ReflectionClass(\Ichiloto\Engine\Util\Debug::class)->getStaticProperties();
  \Ichiloto\Engine\Util\Debug::configure(['log_directory' => createTestDirectory('ichiloto-facing-') . '/logs']);
  $this->oldConfig = new ReflectionClass(\Ichiloto\Engine\Util\Config\ConfigStore::class)->getStaticProperties();
  putSceneAudioConfig(['ui' => ['hud' => ['location' => false]], 'audio' => ['music' => false, 'sfx' => false, 'voice' => false, 'master_volume' => 0]]);
  $this->oldEvents = new ReflectionProperty(EventManager::class, 'instance')->getValue();
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  $game = new PassiveGame();
  $map = $this->getMockBuilder(MapManager::class)->disableOriginalConstructor()
    ->onlyMethods(['canMoveTo'])->getMock();
  $map->method('canMoveTo')->willReturn(false);
  $scene = new class($game, $map) extends GameScene {
    public array $startedScripts = [];
    public function startEventScript(array $commands, ?string $scriptId = null,
      ?\Ichiloto\Engine\Events\Interpreter\EventSessionCompletionTargetInterface $completionTarget = null,
      array $origin = []): ?\Ichiloto\Engine\Events\Interpreter\EventExecutionSession
    { $this->startedScripts[] = $commands; return null; }
    public function __construct(Game $game, MapManager $map)
    {
      $this->sceneManager = new class($game) extends SceneManager {
        public function __construct(Game $game) { $this->game = $game; }
      };
      $this->sceneManager->currentScene = $this;
      $this->gameState = new GameState();
      $this->camera = new Camera($this, 10, 5, worldSpace: []);
      $this->mapManager = $map;
      $this->player = new FacingProbePlayer($this, 'Synthetic', new Vector2(2, 2), new Rect(0, 0, 1, 1), ['v']);
    }
  };
  $this->scene = $scene;
  $this->player = $scene->player;
});

afterEach(function () {
  foreach ($this->oldDebug as $name => $value) { new ReflectionProperty(\Ichiloto\Engine\Util\Debug::class, $name)->setValue(null, $value); }
  foreach ($this->oldConfig as $name => $value) { new ReflectionProperty(\Ichiloto\Engine\Util\Config\ConfigStore::class, $name)->setValue(null, $value); }
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, $this->oldEvents);
});

it('validates authored facing names strictly and preserves the unrestricted default', function () {
  $args = ['class' => FacingProbeTrigger::class, 'area' => ['cells' => [[2, 2]]], 'data' => []];
  foreach ([null, 'North', ['north'], ['None'], [true], ['N' => 'North']] as $invalid) {
    expect(fn() => EventTriggerFactory::create([...$args, 'facing' => $invalid]))->toThrow(InvalidArgumentException::class);
  }
  $any = EventTriggerFactory::create($args);
  foreach (MovementHeading::cases() as $heading) { expect($any->canEngage(new Vector2(2, 2), $heading))->toBeTrue(); }
  $gated = EventTriggerFactory::create([...$args, 'facing' => ['North', 'West', 'North']]);
  expect($gated->facing)->toBe([MovementHeading::NORTH, MovementHeading::WEST])
    ->and($gated->canEngage(new Vector2(2, 2), MovementHeading::NORTH))->toBeTrue()
    ->and($gated->canEngage(new Vector2(2, 2), MovementHeading::SOUTH))->toBeFalse()
    ->and($gated->canEngage(new Vector2(9, 9), MovementHeading::NORTH))->toBeFalse();
});

it('switches facing prompts on blocked turns in either authored order without a walking event', function (bool $reverse) {
  $north = new FacingProbeTrigger(new Rect(2, 2, 1, 1), facing: ['North']);
  $east = new FacingProbeTrigger(new Rect(2, 2, 1, 1), facing: ['East']);
  foreach ($reverse ? [$east, $north] : [$north, $east] as $event) { $this->player->addTrigger($event); }
  expect($this->player->tryMove(Vector2::right(), $this->scene->camera, StepOrigin::PLAYER_INPUT))->toBeFalse()
    ->and($this->player->availableAction->owner)->toBe($east);
  $this->player->tryMove(Vector2::up(), $this->scene->camera, StepOrigin::PLAYER_INPUT);
  expect($this->player->availableAction->owner)->toBe($north)
    ->and($east->calls)->toBe(['enter', 'exit'])
    ->and([$this->player->position->x, $this->player->position->y])->toBe([2.0, 2.0]);
  $this->player->tryMove(Vector2::up(), $this->scene->camera, StepOrigin::PLAYER_INPUT);
  expect($north->calls)->toBe(['enter']);
  $this->player->face(Vector2::down(), $this->scene->camera);
  expect($this->player->availableAction)->toBeNull()->and($north->calls)->toBe(['enter', 'exit']);
})->with([false, true]);

it('leaves unrestricted triggers untouched by turns and preserves movement enter stay exit', function () {
  $event = new FacingProbeTrigger(new Rect(2, 2, 1, 1));
  $this->player->addTrigger($event);
  $this->player->face(Vector2::up(), $this->scene->camera);
  expect($event->calls)->toBe([]);
  $this->player->dispatchMovement(new Vector2(1, 2), new Vector2(2, 2));
  $this->player->face(Vector2::right(), $this->scene->camera);
  $this->player->dispatchMovement(new Vector2(2, 2), new Vector2(2, 2));
  $this->player->dispatchMovement(new Vector2(2, 2), new Vector2(3, 2));
  expect($event->calls)->toBe(['enter', 'stay', 'exit']);
});

it('restores only matching opted-in prompts and gates automatic entry by restored facing', function () {
  $action = new FacingProbeTrigger(new Rect(2, 2, 1, 1), facing: ['North']);
  $auto = new FacingAutomaticProbe(new Rect(2, 2, 1, 1), facing: ['East']);
  $this->player->addTrigger($action); $this->player->addTrigger($auto);
  $this->player->setFacingSprite(['North'], engageEvents: false);
  expect($action->calls)->toBe([]);
  $this->player->evaluateAutomaticTriggersAtCurrentPosition();
  expect($action->calls)->toBe(['enter'])->and($auto->calls)->toBe([]);
  $this->player->setFacingSprite(['East']);
  $this->player->evaluateAutomaticTriggersAtCurrentPosition();
  expect($action->calls)->toBe(['enter', 'exit'])->and($auto->calls)->toBe(['enter']);
});

it('keeps world gates separate and retires changed or completed events', function () {
  $event = new FacingProbeTrigger(new Rect(2, 2, 1, 1),
    conditions: [['type' => 'switch', 'name' => 'ready']], whenBlocked: 'Closed', facing: ['North']);
  $event->bind($this->scene->gameState);
  $this->player->addTrigger($event);
  $this->player->face(Vector2::up(), $this->scene->camera);
  expect($this->player->availableAction)->toBeNull()->and($event->isAvailable())->toBeFalse();
  $this->scene->gameState->setSwitch('ready', true);
  $this->player->face(Vector2::right(), $this->scene->camera);
  expect($event->isAvailable())->toBeTrue()->and($event->canEngage($this->player->position, $this->player->heading))->toBeFalse();
  $this->player->face(Vector2::up(), $this->scene->camera);
  $this->scene->gameState->setSwitch('ready', false);
  $this->player->reconcileActiveEventState();
  expect($this->player->availableAction)->toBeNull()->and($event->calls)->toBe(['enter', 'exit']);
});


it('restores completion and temporary transforms without replaying an opted-in event', function () {
  $event = new FacingProbeTrigger(new Rect(2, 2, 1, 1), ['reusable' => false], facing: ['North']);
  $this->player->addTrigger($event);
  $this->player->face(Vector2::up(), $this->scene->camera);
  $event->restoreCompleted();
  $this->player->reconcileActiveEventState();
  $this->player->face(Vector2::right(), $this->scene->camera);
  $this->player->restoreFieldTransform(new Vector2(2, 2), MovementHeading::NORTH, ['^']);
  $this->player->evaluateAutomaticTriggersAtCurrentPosition();
  expect($this->player->availableAction)->toBeNull()->and($event->calls)->toBe(['enter', 'exit']);
});


it('offers the concrete sleep or script action according to facing on one shared cell', function () {
  $area = new Rect(2, 2, 1, 1);
  $sleep = new \Ichiloto\Engine\Events\Triggers\SleepEventTrigger($area,
    ['confirmDialogue' => ['text' => 'Synthetic rest?'], 'spawnPoint' => ['x' => 2, 'y' => 2], 'spawnSprite' => ['South']], facing: ['North']);
  $script = new \Ichiloto\Engine\Events\Triggers\ScriptEventTrigger($area,
    ['mode' => 'action', 'script' => []], facing: ['West']);
  $this->player->addTrigger($sleep); $this->player->addTrigger($script);
  $this->player->face(Vector2::left(), $this->scene->camera);
  expect($this->player->availableAction)->toBeInstanceOf(\Ichiloto\Engine\Entities\Actions\RunScriptAction::class);
  $this->player->face(Vector2::up(), $this->scene->camera);
  expect($this->player->availableAction)->toBeInstanceOf(\Ichiloto\Engine\Entities\Actions\SleepAction::class);
});

it('validates object engagement and retains inside as the default', function () {
  $args = ['class' => FacingProbeTrigger::class, 'area' => ['cells' => [[2, 1]]], 'data' => []];
  foreach ([null, false, [], 'Facing', 'adjacent', ''] as $invalid) {
    expect(fn() => EventTriggerFactory::create([...$args, 'engagement' => $invalid]))->toThrow(InvalidArgumentException::class);
  }
  expect(EventTriggerFactory::create($args)->engagement)->toBe(EventEngagement::INSIDE)
    ->and(EventTriggerFactory::create([...$args, 'engagement' => 'facing'])->engagement)->toBe(EventEngagement::FACING);
});

it('reaches only the immediate faced object cell in every cardinal direction', function (MovementHeading $heading) {
  $direction = $heading->getDirection();
  $object = new Vector2(2 + $direction->x, 2 + $direction->y);
  $event = new FacingProbeTrigger(new Rect($object->x, $object->y, 1, 1), engagement: EventEngagement::FACING);
  expect($event->canEngage(new Vector2(2, 2), $heading))->toBeTrue()
    ->and($event->canEngage($object, $heading))->toBeFalse()
    ->and($event->canEngage(new Vector2(2 - $direction->x, 2 - $direction->y), $heading))->toBeFalse()
    ->and($event->canEngage(new Vector2(2 + $direction->y, 2 + $direction->x), $heading))->toBeFalse()
    ->and($event->canEngage(new Vector2(2, 2), MovementHeading::NONE))->toBeFalse();
})->with([MovementHeading::NORTH, MovementHeading::EAST, MovementHeading::SOUTH, MovementHeading::WEST]);

it('uses exact object cells and combines reach with optional headings and world conditions', function () {
  $event = EventTriggerFactory::create(['class' => FacingProbeTrigger::class,
    'area' => ['cells' => [[2, 1], [4, 1], [2, 0]]], 'data' => [], 'engagement' => 'facing',
    'facing' => ['North'], 'conditions' => [['type' => 'switch', 'name' => 'ready']],
    'cue' => ['symbol' => '?', 'color' => 'yellow']]);
  $event->bind($this->scene->gameState);
  expect($event->canEngage(new Vector2(2, 2), MovementHeading::NORTH))->toBeFalse();
  $this->scene->gameState->setSwitch('ready', true);
  expect($event->canEngage(new Vector2(2, 2), MovementHeading::NORTH))->toBeTrue()
    ->and($event->canEngage(new Vector2(4, 2), MovementHeading::NORTH))->toBeTrue()
    ->and($event->canEngage(new Vector2(3, 2), MovementHeading::NORTH))->toBeFalse()
    ->and($event->canEngage(new Vector2(1, 1), MovementHeading::EAST))->toBeFalse()
    ->and($event->shouldRenderCue())->toBeTrue();
});

it('switches concrete object prompts on blocked turns in either authored order', function (bool $reverse) {
  $sleep = new \Ichiloto\Engine\Events\Triggers\SleepEventTrigger(new Rect(3, 2, 1, 2),
    ['confirmDialogue' => ['text' => 'Synthetic rest?'], 'spawnPoint' => ['x' => 2, 'y' => 2], 'spawnSprite' => ['South']],
    engagement: EventEngagement::FACING);
  $script = new \Ichiloto\Engine\Events\Triggers\ScriptEventTrigger(new Rect(2, 1, 1, 1),
    ['mode' => 'action', 'script' => []], engagement: EventEngagement::FACING);
  foreach ($reverse ? [$script, $sleep] : [$sleep, $script] as $event) { $this->player->addTrigger($event); }
  expect($this->player->tryMove(Vector2::right(), $this->scene->camera, StepOrigin::PLAYER_INPUT))->toBeFalse()
    ->and($this->player->availableAction)->toBeInstanceOf(\Ichiloto\Engine\Entities\Actions\SleepAction::class);
  $this->player->tryMove(Vector2::up(), $this->scene->camera, StepOrigin::PLAYER_INPUT);
  expect($this->player->availableAction)->toBeInstanceOf(\Ichiloto\Engine\Entities\Actions\RunScriptAction::class)
    ->and([$this->player->position->x, $this->player->position->y])->toBe([2.0, 2.0]);
  $this->player->face(Vector2::down(), $this->scene->camera);
  expect($this->player->availableAction)->toBeNull();
})->with([false, true]);

it('engages on arrival and retires when leaving object reach', function () {
  $event = new FacingProbeTrigger(new Rect(2, 1, 2, 1), engagement: EventEngagement::FACING);
  $this->player->addTrigger($event);
  $this->player->setFacingSprite(['North'], engageEvents: false);
  $this->player->dispatchMovement(new Vector2(2, 3), new Vector2(2, 2));
  $this->player->dispatchMovement(new Vector2(2, 2), new Vector2(3, 2));
  $this->player->dispatchMovement(new Vector2(3, 2), new Vector2(4, 2));
  expect($event->calls)->toBe(['enter', 'stay', 'exit'])->and($this->player->availableAction)->toBeNull();
});

it('restores adjacent prompts and one-shot completion by the existing map marker identity', function () {
  \Ichiloto\Engine\Util\Config\ConfigStore::put(\Ichiloto\Engine\Util\Stores\ItemStore::class,
    new class extends \Ichiloto\Engine\Util\Stores\ItemStore { public function __construct() {} });
  $args = ['class' => \Ichiloto\Engine\Events\Triggers\ChestEventTrigger::class,
    'area' => ['cells' => [[2, 1]]], 'marker' => 'C', 'data' => [], 'engagement' => 'facing'];
  $chest = EventTriggerFactory::create($args, 'synthetic-object-map');
  $chest->bind($this->scene->gameState);
  $this->player->addTrigger($chest);
  $this->player->setFacingSprite(['North'], engageEvents: false);
  $this->player->evaluateAutomaticTriggersAtCurrentPosition();
  expect($this->player->availableAction)->toBeInstanceOf(\Ichiloto\Engine\Entities\Actions\ChestOpeningAction::class);
  $chest->complete();
  $this->player->reconcileActiveEventState();
  expect($this->player->availableAction)->toBeNull();
  $restored = EventTriggerFactory::create($args, 'synthetic-object-map');
  $savedState = GameState::fromArray($this->scene->gameState->toArray());
  $restored->bind($savedState);
  if ($savedState->isEventComplete('synthetic-object-map', 'C')) { $restored->restoreCompleted(); }
  expect($restored->isComplete)->toBeTrue()
    ->and($restored->canEngage(new Vector2(2, 2), MovementHeading::NORTH))->toBeFalse();
});

it('preserves automatic trigger behavior for faced object areas without replay on unchanged heading', function () {
  $event = new FacingAutomaticProbe(new Rect(2, 1, 1, 1), engagement: EventEngagement::FACING);
  $this->player->addTrigger($event);
  $this->player->setFacingSprite(['North'], engageEvents: false);
  $this->player->evaluateAutomaticTriggersAtCurrentPosition();
  $this->player->evaluateAutomaticTriggersAtCurrentPosition();
  $this->player->face(Vector2::up(), $this->scene->camera);
  expect($event->calls)->toBe(['enter']);
  $this->player->face(Vector2::right(), $this->scene->camera);
  expect($event->calls)->toBe(['enter', 'exit']);
});

it('waits for semantic interaction before executing an object script', function () {
  $commands = [['type' => 'switch', 'name' => 'synthetic-record-read', 'value' => true]];
  $event = new \Ichiloto\Engine\Events\Triggers\ScriptEventTrigger(new Rect(2, 1, 1, 1),
    ['mode' => 'action', 'script' => $commands], engagement: EventEngagement::FACING);
  $this->player->addTrigger($event);
  $this->player->face(Vector2::up(), $this->scene->camera);
  expect($this->scene->startedScripts)->toBe([]);
  $this->player->interact();
  expect($this->scene->startedScripts)->toBe([$commands]);
  $this->player->face(Vector2::right(), $this->scene->camera);
  expect($this->player->availableAction)->toBeNull();
});
