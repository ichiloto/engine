<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Entities\Interfaces\ActionInterface;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Enumerations\MovementEventType;
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
  $this->oldConfig = new ReflectionClass(\Ichiloto\Engine\Util\Config\ConfigStore::class)->getStaticProperties();
  putSceneAudioConfig(['ui' => ['hud' => ['location' => false]], 'audio' => ['music' => false, 'sfx' => false, 'voice' => false, 'master_volume' => 0]]);
  $this->oldEvents = new ReflectionProperty(EventManager::class, 'instance')->getValue();
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  $game = new PassiveGame();
  $map = $this->getMockBuilder(MapManager::class)->disableOriginalConstructor()
    ->onlyMethods(['canMoveTo'])->getMock();
  $map->method('canMoveTo')->willReturn(false);
  $scene = new class($game, $map) extends GameScene {
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
  expect($this->player->tryMove(Vector2::right(), $this->scene->camera))->toBeFalse()
    ->and($this->player->availableAction->owner)->toBe($east);
  $this->player->tryMove(Vector2::up(), $this->scene->camera);
  expect($this->player->availableAction->owner)->toBe($north)
    ->and($east->calls)->toBe(['enter', 'exit'])
    ->and([$this->player->position->x, $this->player->position->y])->toBe([2.0, 2.0]);
  $this->player->tryMove(Vector2::up(), $this->scene->camera);
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
