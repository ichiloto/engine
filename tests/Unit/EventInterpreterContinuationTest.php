<?php

use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Events\Interpreter\EventSessionCompletionTargetInterface;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Enumerations\MovementEventType;
use Ichiloto\Engine\Events\MovementEvent;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Exceptions\ActiveEventSaveException;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Progress\Bestiary;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use Assegai\Collections\ItemList;

final class EventTestPresentation implements EventPresentationInterface
{
  public bool $complete = false;
  public ?int $choice = null;
  public ?string $kind = null;
  public int $updates = 0;
  public int $resets = 0;

  public function beginText(string $text, string $name = ''): void
  {
    $this->kind = 'text';
    $this->complete = false;
  }

  public function beginChoice(string $prompt, array $options, string $title = ''): void
  {
    $this->kind = 'choice';
    $this->complete = false;
  }

  public function update(): void
  {
    $this->updates++;
  }

  public function render(): void
  {
  }

  public function isComplete(): bool
  {
    return $this->complete;
  }

  public function choiceResult(): ?int
  {
    return $this->choice;
  }

  public function reset(): void
  {
    $this->resets++;
    $this->kind = null;
    $this->complete = false;
    $this->choice = null;
  }

  public function resolveText(): void
  {
    $this->complete = true;
  }

  public function resolveChoice(int $index): void
  {
    $this->choice = $index;
    $this->complete = true;
  }
}

final class EventTestCompletionTarget implements EventSessionCompletionTargetInterface
{
  public int $completed = 0;
  public int $failed = 0;

  public function onEventSessionCompleted(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->completed++;
  }

  public function onEventSessionFailed(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->failed++;
  }
}

class EventTestScriptTrigger extends ScriptEventTrigger
{
  public int $completedSessions = 0;
  public int $failedSessions = 0;
  public bool $restartOnFailure = false;
  public ?EventExecutionSession $restartDuringFailure = null;

  public function onEventSessionCompleted(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->completedSessions++;
    parent::onEventSessionCompleted($gameScene, $session);
  }

  public function onEventSessionFailed(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->failedSessions++;
    parent::onEventSessionFailed($gameScene, $session);

    if ($this->restartOnFailure) {
      $this->restartDuringFailure = $this->startSession($gameScene);
    }
  }
}

class EventTestSaveManager extends SaveManager
{
  public int $autoSaveCount = 0;

  public function __construct()
  {
  }

  public function autoSave(GameScene $scene): SaveSlot
  {
    $this->autoSaveCount++;

    return SaveSlot::empty(self::AUTO_SAVE_SLOT, '/tmp/event-test-auto.iedata');
  }
}

class EventTestGame extends Game
{
  public function __construct()
  {
  }

  public function __destruct()
  {
  }
}

class EventTestSceneManager extends SceneManager
{
  public int $battleCount = 0;
  public ?Troop $lastTroop = null;
  public array $lastBattleSettings = [];

  public function __construct(public EventTestSaveManager $eventTestSaveManager = new EventTestSaveManager())
  {
    $this->saveManager = $eventTestSaveManager;
  }

  public function loadBattleScene(Party $party, Troop $troop, array $events = [], array $extraSettings = []): void
  {
    $this->battleCount++;
    $this->lastTroop = $troop;
    $this->lastBattleSettings = $extraSettings;
  }
}

class EventTestCamera extends Camera
{
  public array $renders = [];

  public function __construct()
  {
  }

  public function renderOnScreen(array $output, Vector2 $worldSpacePosition): void
  {
    $this->renders[] = [$output, clone $worldSpacePosition];
  }
}

class EventTestMapManager extends MapManager
{
  public array $blocked = [];

  public function __construct()
  {
  }

  public function canMoveTo(int $x, int $y, ?\Ichiloto\Engine\Events\Enumerations\CollisionType &$collisionType = null): bool
  {
    return ! isset($this->blocked["{$x}:{$y}"]);
  }

  public function scrollMap(Player $player, Vector2 $moveDirection): bool
  {
    return false;
  }
}

class EventTestPlayer extends Player
{
  public ?string $facing = null;
  public array $blockedMessages = [];
  public int $movementNotifications = 0;

  public function __construct(Vector2 $position)
  {
    $this->position = $position;
    $this->shape = new Rect(0, 0, 1, 1);
    $this->sprite = ['@'];
    $this->observers = new ItemList(\Ichiloto\Engine\Events\Interfaces\ObserverInterface::class);
    $this->staticObservers = new ItemList(\Ichiloto\Engine\Events\Interfaces\StaticObserverInterface::class);
    $this->events = new ItemList(EventTrigger::class);
    $this->eventManager = EventManager::getInstance(new EventTestGame());
  }

  public function tryMove(Vector2 $direction, Camera $camera): bool
  {
    $scene = $this->scene ?? null;
    $destinationX = intval($this->position->x + $direction->x);
    $destinationY = intval($this->position->y + $direction->y);

    if ($scene instanceof GameScene && ! $scene->mapManager?->canMoveTo($destinationX, $destinationY)) {
      return false;
    }

    $this->position->x = $destinationX;
    $this->position->y = $destinationY;
    $this->face($direction, $camera);

    return true;
  }

  public function tryFieldMove(Vector2 $direction, Camera $camera): bool
  {
    return parent::tryMove($direction, $camera);
  }

  public function face(Vector2 $direction, Camera $camera): void
  {
    $this->facing = match (true) {
      $direction->y < 0 => 'up',
      $direction->y > 0 => 'down',
      $direction->x < 0 => 'left',
      default => 'right',
    };
  }

  public function render(): void
  {
  }

  public function erase(): void
  {
  }

  public function bindScene(GameScene $scene): void
  {
    $this->scene = $scene;
  }

  protected function announceBlockedEvent(string $message): void
  {
    $this->blockedMessages[] = $message;
  }

  public function notify(object $entity, \Ichiloto\Engine\Events\Interfaces\EventInterface $event): void
  {
    $this->movementNotifications++;
  }

  public function dispatchMovement(Vector2 $origin, Vector2 $destination): void
  {
    $this->position = clone $destination;
    $this->handleTriggers(new MovementEvent(
      MovementEventType::PLAYER_MOVE,
      clone $origin,
      clone $destination,
    ));
  }

  public function hasActiveEvent(EventTrigger $event): bool
  {
    return $this->eventManager->activeEvents->contains($event);
  }
}

class EventTestGameScene extends GameScene
{
  public array $finished = [];
  public array $restoredTiles = [];

  public function __construct(public EventTestSceneManager $testSceneManager = new EventTestSceneManager())
  {
    $this->sceneManager = $testSceneManager;
    $this->gameState = new GameState();
    $this->hasDeferredAutoSave = false;
    $this->currentMapId = 'map-a';
    $this->camera = new EventTestCamera();
    $this->mapManager = new EventTestMapManager();
    $this->party = new Party();
    $this->bestiary = new Bestiary();
  }

  public function installInterpreter(EventInterpreter $interpreter): void
  {
    $this->eventInterpreter = $interpreter;
  }

  public function installPlayer(EventTestPlayer $player): void
  {
    $player->bindScene($this);
    $this->player = $player;
  }

  public function installNpcManager(NpcManager $npcManager): void
  {
    $this->npcManager = $npcManager;
  }

  public function deferAutoSaveForTesting(): void
  {
    $this->hasDeferredAutoSave = true;
  }

  public function transferPlayer(Location $location): void
  {
    $this->currentMapId = $location->mapFilename;
    $this->eventInterpreter?->resumeAfterTransfer();
    $this->autoSave();
  }

  public function onEventSessionStarted(EventExecutionSession $session): void
  {
  }

  public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void
  {
    $this->finished[] = [$session->id, $completed];
    parent::onEventSessionFinished($session, $completed);
  }

  public function renderBackgroundTile(int $x, int $y): void
  {
    $this->restoredTiles[] = [$x, $y];
  }
}

/** @return array{EventTestGameScene, EventInterpreter, EventTestPresentation} */
function makeEventRuntime(): array
{
  $scene = new EventTestGameScene();
  $presentation = new EventTestPresentation();
  $interpreter = new EventInterpreter($scene, $presentation);
  $scene->installInterpreter($interpreter);

  return [$scene, $interpreter, $presentation];
}

it('keeps immediate scripts compatible and completes once', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $target = new EventTestCompletionTarget();

  $session = $interpreter->run([
    ['type' => 'set_switch', 'name' => 'awake', 'value' => true],
    ['type' => 'set_variable', 'name' => 'visits', 'value' => 1],
    ['type' => 'record_event', 'name' => 'technical_event'],
  ], 'simple', $target);

  expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->gameState->getSwitch('awake'))->toBeTrue()
    ->and($scene->gameState->getVariable('visits'))->toBe(1)
    ->and($scene->gameState->hasStoryEvent('technical_event'))->toBeTrue()
    ->and($target->completed)->toBe(1)
    ->and($target->failed)->toBe(0);
});

it('fails closed on an unknown top-level command and permits a corrected trigger retry', function () {
  $root = sys_get_temp_dir() . '/event-unknown-' . uniqid();
  mkdir($root . '/assets/Events', 0o777, true);
  mkdir($root . '/assets/Data', 0o777, true);
  file_put_contents($root . '/assets/Events/fail-closed.php', <<<'PHP'
  <?php
  return [
    ['type' => 'set_switch', 'name' => 'before_unknown', 'value' => true],
    ['type' => 'parallel_cutscene'],
    ['type' => 'set_switch', 'name' => 'after_unknown', 'value' => true],
    ['type' => 'record_event', 'name' => 'forbidden_story_flag'],
    ['type' => 'accept_quest', 'id' => 'forbidden-quest', 'confirm' => false],
    ['type' => 'give_gold', 'amount' => 500],
  ];
  PHP);
  file_put_contents($root . '/assets/Data/quests.php', <<<'PHP'
  <?php
  return [[
    'id' => 'forbidden-quest',
    'name' => 'Forbidden Quest',
    'objectives' => [['type' => 'talk_to', 'target' => 'Nobody']],
  ]];
  PHP);
  $previousDirectory = getcwd();
  chdir($root);
  $questManagerProperty = new ReflectionProperty(QuestManager::class, 'current');

  try {
    [$scene, $interpreter, $presentation] = makeEventRuntime();
    $scene->installPlayer(new EventTestPlayer(new Vector2(1, 1)));
    $questManager = new QuestManager(new EventTestGame(), $scene);
    $scene->deferAutoSaveForTesting();
    $trigger = new EventTestScriptTrigger(
      new Rect(0, 0, 1, 1),
      ['mode' => 'auto', 'reusable' => false, 'scriptId' => 'fail-closed'],
      sets: [
        ['type' => 'variable', 'name' => 'completion_count', 'op' => 'add', 'value' => 1],
        ['type' => 'event', 'name' => 'trigger_completion_flag'],
      ],
      mapId: 'map-a',
      marker: 'U',
    );
    $trigger->bind($scene->gameState, $scene->party);
    $trigger->restartOnFailure = true;

    $session = $trigger->startSession($scene);

    expect($session?->status)->toBe(EventExecutionStatus::FAILED)
      ->and($session?->failureMessage)->toContain('Unknown event command type "parallel_cutscene"')
      ->and($session?->failureMessage)->toContain('script "fail-closed"')
      ->and($session?->failureMessage)->toContain('command 2')
      ->and($session?->failureMessage)->toContain('frame "fail-closed"')
      ->and($session?->failureMessage)->toContain('map "map-a"')
      ->and($session?->failureMessage)->toContain('marker "U"')
      ->and($session?->failureMessage)->toContain('trigger "' . EventTestScriptTrigger::class . '"')
      ->and($session?->failureMessage)->toContain('/assets/Events/fail-closed.php"')
      ->and($session?->pendingCommand)->toBeNull()
      ->and($session?->pendingState)->toBe([])
      ->and($scene->gameState->getSwitch('before_unknown'))->toBeTrue()
      ->and($scene->gameState->getSwitch('after_unknown'))->toBeFalse()
      ->and($scene->gameState->hasStoryEvent('forbidden_story_flag'))->toBeFalse()
      ->and($scene->gameState->hasStoryEvent('trigger_completion_flag'))->toBeFalse()
      ->and($scene->gameState->getVariable('completion_count'))->toBe(0)
      ->and($questManager->log->isActive('forbidden-quest'))->toBeFalse()
      ->and($scene->party->accountBalance)->toBe(0)
      ->and($trigger->isComplete)->toBeFalse()
      ->and($trigger->failedSessions)->toBe(1)
      ->and($trigger->completedSessions)->toBe(0)
      ->and($trigger->restartDuringFailure)->toBeNull()
      ->and($interpreter->hasActiveSession())->toBeFalse()
      ->and($scene->hasDeferredAutoSave)->toBeFalse()
      ->and($scene->testSceneManager->eventTestSaveManager->autoSaveCount)->toBe(0)
      ->and($presentation->resets)->toBeGreaterThanOrEqual(1);

    $manifest = SaveCompatibilityManifest::fromArray('ichiloto/event-fail-closed', [
      'contentVersion' => 0,
      'migrations' => [],
      'aliases' => [],
      'tombstones' => [],
    ], 'Fail-closed event test manifest');
    $saveManager = new SaveManager(new EventTestGame(), 'saves', 'saves/quick', $manifest);
    $savedSlot = $saveManager->save($scene, 1);
    expect(file_get_contents($savedSlot->path))->toStartWith('IED1');

    file_put_contents($root . '/assets/Events/fail-closed.php', <<<'PHP'
    <?php
    return [
      ['type' => 'set_variable', 'name' => 'corrected_retry_count', 'op' => 'add', 'value' => 1],
    ];
    PHP);
    $trigger->configure();
    $retry = $trigger->startSession($scene);

    expect($retry?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($trigger->isComplete)->toBeTrue()
      ->and($trigger->failedSessions)->toBe(1)
      ->and($trigger->completedSessions)->toBe(1)
      ->and($scene->gameState->getVariable('corrected_retry_count'))->toBe(1)
      ->and($scene->gameState->getVariable('completion_count'))->toBe(1)
      ->and($scene->gameState->hasStoryEvent('trigger_completion_flag'))->toBeTrue()
      ->and(array_count_values($scene->gameState->storyEvents)['trigger_completion_flag'])->toBe(1);
  } finally {
    $questManagerProperty->setValue(null, null);
    chdir($previousDirectory);
  }
});

it('propagates unknown commands out of nested branch arms before parent commands', function (bool $condition, string $arm) {
  [$scene, $interpreter] = makeEventRuntime();
  $scene->gameState->setSwitch('branch_condition', $condition);
  $target = new EventTestCompletionTarget();
  $session = $interpreter->run([
    [
      'type' => 'branch',
      'conditions' => [['type' => 'switch', 'name' => 'branch_condition']],
      'then' => [
        ['type' => 'set_switch', 'name' => 'inside_then', 'value' => true],
        ['type' => 'unknown_then'],
        ['type' => 'set_switch', 'name' => 'after_then_unknown', 'value' => true],
      ],
      'else' => [
        ['type' => 'set_switch', 'name' => 'inside_else', 'value' => true],
        ['type' => 'unknown_else'],
        ['type' => 'set_switch', 'name' => 'after_else_unknown', 'value' => true],
      ],
    ],
    ['type' => 'set_switch', 'name' => 'after_parent_branch', 'value' => true],
  ], 'nested-branch', $target);

  expect($session?->status)->toBe(EventExecutionStatus::FAILED)
    ->and($session?->failureMessage)->toContain('Unknown event command type "unknown_' . $arm . '"')
    ->and($session?->failureMessage)->toContain('command 2')
    ->and($session?->failureMessage)->toContain('frame "branch:' . $arm . '"')
    ->and($scene->gameState->getSwitch('inside_' . $arm))->toBeTrue()
    ->and($scene->gameState->getSwitch('after_' . $arm . '_unknown'))->toBeFalse()
    ->and($scene->gameState->getSwitch('after_parent_branch'))->toBeFalse()
    ->and($target->completed)->toBe(0)
    ->and($target->failed)->toBe(1)
    ->and($interpreter->hasActiveSession())->toBeFalse();
})->with([
  'then arm' => [true, 'then'],
  'else arm' => [false, 'else'],
]);

it('propagates an unknown choice-arm command before later arm and parent commands', function () {
  [$scene, $interpreter, $presentation] = makeEventRuntime();
  $target = new EventTestCompletionTarget();
  $session = $interpreter->run([
    [
      'type' => 'choice',
      'prompt' => 'Choose the technical arm.',
      'options' => [[
        'text' => 'Continue',
        'then' => [
          ['type' => 'set_switch', 'name' => 'inside_choice', 'value' => true],
          ['type' => 'unknown_choice'],
          ['type' => 'set_switch', 'name' => 'after_choice_unknown', 'value' => true],
        ],
      ]],
    ],
    ['type' => 'set_switch', 'name' => 'after_parent_choice', 'value' => true],
  ], 'nested-choice', $target);

  $presentation->resolveChoice(0);
  $interpreter->update(0.016);

  expect($session?->status)->toBe(EventExecutionStatus::FAILED)
    ->and($session?->failureMessage)->toContain('Unknown event command type "unknown_choice"')
    ->and($session?->failureMessage)->toContain('command 2')
    ->and($session?->failureMessage)->toContain('frame "choice:0"')
    ->and($scene->gameState->getSwitch('inside_choice'))->toBeTrue()
    ->and($scene->gameState->getSwitch('after_choice_unknown'))->toBeFalse()
    ->and($scene->gameState->getSwitch('after_parent_choice'))->toBeFalse()
    ->and($target->completed)->toBe(0)
    ->and($target->failed)->toBe(1)
    ->and($interpreter->hasActiveSession())->toBeFalse();
});

it('yields waits without blocking and retains a nested branch frame', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $scene->gameState->setSwitch('take_branch', true);

  $session = $interpreter->run([
    ['type' => 'branch', 'conditions' => [['type' => 'switch', 'name' => 'take_branch']], 'then' => [
      ['type' => 'wait', 'seconds' => 1.0],
      ['type' => 'set_variable', 'name' => 'after_wait', 'value' => 7],
    ]],
    ['type' => 'set_switch', 'name' => 'after_branch', 'value' => true],
  ]);

  expect($session?->status)->toBe(EventExecutionStatus::YIELDED)
    ->and($session?->pendingState['remainingSeconds'])->toBe(1.0)
    ->and($session?->frames())->toHaveCount(2)
    ->and($scene->gameState->getVariable('after_wait'))->toBe(0);

  $interpreter->update(0.4);
  expect($session?->pendingState['remainingSeconds'])->toBe(0.6);

  $interpreter->update(0.6);
  expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->gameState->getVariable('after_wait'))->toBe(7)
    ->and($scene->gameState->getSwitch('after_branch'))->toBeTrue();
});

it('resumes dialogue and choices on later ticks', function () {
  [$scene, $interpreter, $presentation] = makeEventRuntime();

  $session = $interpreter->run([
    ['type' => 'text', 'name' => 'Tester', 'text' => 'Technical dialogue.'],
    ['type' => 'choice', 'prompt' => 'Continue?', 'options' => [
      ['text' => 'Yes', 'then' => [['type' => 'set_variable', 'name' => 'choice', 'value' => 'yes']]],
      ['text' => 'No', 'then' => [['type' => 'set_variable', 'name' => 'choice', 'value' => 'no']]],
    ]],
    ['type' => 'set_switch', 'name' => 'finished', 'value' => true],
  ]);

  expect($session?->status)->toBe(EventExecutionStatus::YIELDED)
    ->and($presentation->kind)->toBe('text');

  $presentation->resolveText();
  $interpreter->update(0.016);
  expect($presentation->kind)->toBe('choice')
    ->and($scene->gameState->getSwitch('finished'))->toBeFalse();

  $presentation->resolveChoice(1);
  $interpreter->update(0.016);
  expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->gameState->getVariable('choice'))->toBe('no')
    ->and($scene->gameState->getSwitch('finished'))->toBeTrue();
});

it('prevents trigger re-entry and applies one-shot completion writes once', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $trigger = new ScriptEventTrigger(
    new \Ichiloto\Engine\Core\Rect(0, 0, 1, 1),
    ['mode' => 'auto', 'reusable' => false, 'script' => [
      ['type' => 'wait', 'seconds' => 0.1],
    ]],
    sets: [['type' => 'variable', 'name' => 'reward_count', 'op' => 'add', 'value' => 1]],
    mapId: 'map-a',
    marker: 'E',
  );
  $trigger->bind($scene->gameState, $scene->party);

  $first = $trigger->startSession($scene);
  $second = $trigger->startSession($scene);

  expect($first)->not->toBeNull()
    ->and($second)->toBeNull()
    ->and($interpreter->activeSession())->toBe($first);

  $interpreter->update(0.1);

  expect($trigger->isComplete)->toBeTrue()
    ->and($scene->gameState->isEventComplete('map-a', 'E'))->toBeTrue()
    ->and($scene->gameState->getVariable('reward_count'))->toBe(1)
    ->and($trigger->startSession($scene))->toBeNull()
    ->and($scene->gameState->getVariable('reward_count'))->toBe(1);
});

it('runs exit cleanup after a one-shot action trigger completes in place', function () {
  [$scene] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(0, 0));
  $scene->installPlayer($player);
  $trigger = new ScriptEventTrigger(
    new Rect(1, 0, 1, 1),
    ['mode' => 'action', 'reusable' => false, 'script' => [
      ['type' => 'record_event', 'name' => 'one_shot_action_finished'],
    ]],
    mapId: 'map-a',
    marker: 'B',
  );
  $trigger->bind($scene->gameState, $scene->party);
  $player->addTrigger($trigger);

  $player->dispatchMovement(new Vector2(0, 0), new Vector2(1, 0));
  expect($player->availableAction)->not->toBeNull();

  $player->interact();
  expect($trigger->isComplete)->toBeTrue()
    ->and($scene->gameState->hasStoryEvent('one_shot_action_finished'))->toBeTrue()
    ->and($player->availableAction)->not->toBeNull();

  $player->dispatchMovement(new Vector2(1, 0), new Vector2(2, 0));

  expect($player->availableAction)->toBeNull();
});

it('retires map-owned action state when event triggers are replaced', function () {
  [$scene] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(0, 0));
  $scene->installPlayer($player);
  $trigger = new ScriptEventTrigger(
    new Rect(1, 0, 1, 1),
    ['mode' => 'action', 'reusable' => true, 'script' => [
      ['type' => 'record_event', 'name' => 'old_map_action_ran'],
    ]],
    mapId: 'map-a',
    marker: 'C',
  );
  $trigger->bind($scene->gameState, $scene->party);
  $player->addTrigger($trigger);

  $player->dispatchMovement(new Vector2(0, 0), new Vector2(1, 0));

  expect($player->availableAction)->not->toBeNull()
    ->and($player->hasActiveEvent($trigger))->toBeTrue();

  // This is the lifecycle used when a transfer replaces the source map's
  // trigger definitions with the destination map's definitions.
  $player->removeTriggers();

  expect($player->availableAction)->toBeNull()
    ->and($player->hasActiveEvent($trigger))->toBeFalse();

  $player->interact();

  expect($scene->gameState->hasStoryEvent('old_map_action_ran'))->toBeFalse();
});

it('fails closed when a prompted script trigger becomes unavailable before execution', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(0, 0));
  $scene->installPlayer($player);
  $scene->gameState->setSwitch('boss_available', true);
  $trigger = new ScriptEventTrigger(
    new Rect(1, 0, 1, 1),
    ['mode' => 'action', 'reusable' => true, 'script' => [
      ['type' => 'record_event', 'name' => 'boss_started_again'],
    ]],
    conditions: [['type' => 'switch', 'name' => 'boss_available']],
    mapId: 'map-a',
    marker: 'C',
  );
  $trigger->bind($scene->gameState, $scene->party);
  $player->addTrigger($trigger);
  $player->dispatchMovement(new Vector2(0, 0), new Vector2(1, 0));

  expect($player->availableAction)->not->toBeNull();

  $scene->gameState->setSwitch('boss_available', false);
  $player->interact();

  expect($interpreter->hasActiveSession())->toBeFalse()
    ->and($scene->gameState->hasStoryEvent('boss_started_again'))->toBeFalse();
});

it('rejects unavailable field gates before movement advances any field state', function () {
  [$scene] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(0, 0));
  $scene->installPlayer($player);
  $trigger = new \Ichiloto\Engine\Events\Triggers\DialogueEventTrigger(
    new Rect(1, 0, 2, 1),
    ['dialogue' => [['name' => '', 'text' => 'The route is open.']]],
    conditions: [['type' => 'switch', 'name' => 'gate_open']],
    whenBlocked: 'The route is locked.',
  );
  $trigger->bind($scene->gameState, $scene->party);
  $player->addTrigger($trigger);

  expect($player->tryFieldMove(Vector2::right(), $scene->camera))->toBeFalse()
    ->and($player->position->x)->toBe(0.0)
    ->and($player->position->y)->toBe(0.0)
    ->and($player->blockedMessages)->toBe(['The route is locked.'])
    ->and($player->movementNotifications)->toBe(0);

  // Repeated input against the same boundary stays blocked without stacking
  // alerts. Moving away starts a new approach and permits another explanation.
  expect($player->tryFieldMove(Vector2::right(), $scene->camera))->toBeFalse()
    ->and($player->blockedMessages)->toHaveCount(1)
    ->and($player->tryFieldMove(Vector2::left(), $scene->camera))->toBeTrue()
    ->and($player->tryFieldMove(Vector2::right(), $scene->camera))->toBeTrue()
    ->and($player->tryFieldMove(Vector2::right(), $scene->camera))->toBeFalse()
    ->and($player->blockedMessages)->toHaveCount(2)
    ->and($player->movementNotifications)->toBe(2);

  $scene->gameState->setSwitch('gate_open', true);

  expect($player->tryFieldMove(Vector2::right(), $scene->camera))->toBeTrue()
    ->and($player->position->x)->toBe(1.0)
    ->and($player->position->y)->toBe(0.0)
    ->and($player->movementNotifications)->toBe(3);
});

it('lets a loaded player already inside a newly locked event area move out', function () {
  [$scene] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(2, 0));
  $scene->installPlayer($player);
  $trigger = new \Ichiloto\Engine\Events\Triggers\DialogueEventTrigger(
    new Rect(1, 0, 2, 1),
    ['dialogue' => [['name' => '', 'text' => 'The route is open.']]],
    conditions: [['type' => 'switch', 'name' => 'gate_open']],
    whenBlocked: 'The route is locked.',
  );
  $trigger->bind($scene->gameState, $scene->party);
  $player->addTrigger($trigger);

  expect($player->tryFieldMove(Vector2::left(), $scene->camera))->toBeTrue()
    ->and($player->tryFieldMove(Vector2::left(), $scene->camera))->toBeTrue()
    ->and($player->position->x)->toBe(0.0)
    ->and($player->position->y)->toBe(0.0)
    ->and($player->blockedMessages)->toBe([]);
});

it('starts an automatic script at the initial field position', function () {
  [$scene] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(1, 1));
  $scene->installPlayer($player);
  $trigger = new ScriptEventTrigger(
    new Rect(1, 1, 1, 1),
    ['mode' => 'auto', 'reusable' => false, 'script' => [
      ['type' => 'record_event', 'name' => 'automatic_trigger_started'],
    ]],
    mapId: 'map-a',
    marker: 'A',
  );
  $trigger->bind($scene->gameState, $scene->party);
  $player->addTrigger($trigger);
  $player->evaluateAutomaticTriggersAtCurrentPosition();

  expect($scene->gameState->hasStoryEvent('automatic_trigger_started'))->toBeTrue()
    ->and($trigger->isComplete)->toBeTrue()
    ->and($scene->gameState->isEventComplete('map-a', 'A'))->toBeTrue();
});

it('defers NPC conversation writes and prevents duplicate rewards while its script is active', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $npc = new \Ichiloto\Engine\Field\Npc(
    name: 'Guide',
    sprite: 'G',
    position: new Vector2(2, 2),
    script: [['type' => 'wait', 'seconds' => 0.1]],
    sets: [['type' => 'variable', 'name' => 'npc_reward_count', 'op' => 'add', 'value' => 1]],
    id: 'guide',
  );

  $npc->talk($scene);
  $npc->talk($scene);

  expect($scene->gameState->getVariable('npc_reward_count'))->toBe(0)
    ->and($interpreter->hasActiveSession())->toBeTrue();

  $interpreter->update(0.1);

  expect($scene->gameState->getVariable('npc_reward_count'))->toBe(1)
    ->and($interpreter->hasActiveSession())->toBeFalse();
});

it('executes player and stable-id NPC routes step by step including facing', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(1, 1));
  $scene->installPlayer($player);
  $npcs = new NpcManager($scene);
  $npcs->configure([[
    'id' => 'guide',
    'name' => 'Guide',
    'sprite' => 'G',
    'x' => 5,
    'y' => 5,
    'sprites' => ['north' => '^', 'south' => 'v', 'east' => '>', 'west' => '<'],
  ]]);
  $scene->installNpcManager($npcs);

  $session = $interpreter->run([
    ['type' => 'move_route', 'subject' => 'player', 'secondsPerStep' => 0, 'steps' => [
      ['direction' => 'right', 'count' => 2],
      ['direction' => 'up', 'count' => 1, 'faceOnly' => true],
    ]],
    ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'guide', 'secondsPerStep' => 0, 'steps' => [
      ['direction' => 'left', 'count' => 2],
      ['direction' => 'up', 'count' => 1, 'faceOnly' => true],
    ]],
  ]);

  // One route unit per tick keeps movement visible and deterministic.
  for ($tick = 0; $tick < 7; $tick++) {
    $interpreter->update(0.016);
  }

  $npc = $npcs->findById('guide');

  expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and([$player->position->x, $player->position->y])->toBe([3.0, 1.0])
    ->and($player->facing)->toBe('up')
    ->and([$npc?->position->x, $npc?->position->y])->toBe([3.0, 5.0])
    ->and($npc?->sprite)->toBe('^');
});

it('fails a blocked route immediately with map and attempted position context', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(1, 1));
  $scene->installPlayer($player);
  assert($scene->mapManager instanceof EventTestMapManager);
  $scene->mapManager->blocked['2:1'] = true;

  $session = $interpreter->run([[
    'type' => 'move_route',
    'subject' => 'player',
    'steps' => [['direction' => 'right', 'count' => 1]],
  ]]);
  $interpreter->update(0.016);

  expect($session?->status)->toBe(EventExecutionStatus::FAILED)
    ->and($session?->failureMessage)->toContain('map-a')
    ->and($session?->failureMessage)->toContain('player step 1')
    ->and($session?->failureMessage)->toContain('(2, 1)')
    ->and($interpreter->hasActiveSession())->toBeFalse();
});

it('fails malformed or parallel route data safely when validation was skipped', function () {
  [, $interpreter] = makeEventRuntime();

  $parallel = $interpreter->run([[
    'type' => 'move_route',
    'subject' => 'player',
    'wait' => 'false',
    'steps' => [['direction' => 'right', 'count' => 1]],
  ]]);

  expect($parallel?->status)->toBe(EventExecutionStatus::FAILED)
    ->and($parallel?->failureMessage)->toContain('wait must be true');

  [, $secondInterpreter] = makeEventRuntime();
  $badCount = $secondInterpreter->run([[
    'type' => 'move_route',
    'subject' => 'player',
    'steps' => [['direction' => 'right', 'count' => 'several']],
  ]]);

  expect($badCount?->status)->toBe(EventExecutionStatus::FAILED)
    ->and($badCount?->failureMessage)->toContain('invalid count');

  [, $thirdInterpreter] = makeEventRuntime();
  $badFacing = $thirdInterpreter->run([[
    'type' => 'move_route',
    'subject' => 'player',
    'steps' => [['direction' => 'right', 'faceOnly' => 'false']],
  ]]);

  expect($badFacing?->status)->toBe(EventExecutionStatus::FAILED)
    ->and($badFacing?->failureMessage)->toContain('invalid faceOnly');
});

it('resumes after an in-script transfer and defers transfer autosave until completion', function () {
  putSceneAudioConfig(['save' => ['autosave' => true]]);
  [$scene, $interpreter] = makeEventRuntime();

  $session = $interpreter->run([
    ['type' => 'set_switch', 'name' => 'before_transfer', 'value' => true],
    ['type' => 'transfer', 'map' => 'map-b', 'x' => 2, 'y' => 3],
    ['type' => 'set_switch', 'name' => 'after_transfer', 'value' => true],
  ]);

  expect($session?->status)->toBe(EventExecutionStatus::RUNNING)
    ->and($scene->currentMapId)->toBe('map-b')
    ->and($scene->hasDeferredAutoSave)->toBeTrue()
    ->and($scene->testSceneManager->eventTestSaveManager->autoSaveCount)->toBe(0);

  $interpreter->update(0.016);

  expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->gameState->getSwitch('before_transfer'))->toBeTrue()
    ->and($scene->gameState->getSwitch('after_transfer'))->toBeTrue()
    ->and($scene->hasDeferredAutoSave)->toBeFalse()
    ->and($scene->testSceneManager->eventTestSaveManager->autoSaveCount)->toBe(1);

  ConfigStore::remove(ProjectConfig::class);
});

it('blocks manual saves and quicksaves while a session is unstable', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $interpreter->run([['type' => 'wait', 'seconds' => 5]]);
  $root = sys_get_temp_dir() . '/event-save-' . uniqid();
  $manager = new SaveManager(
    new EventTestGame(),
    $root,
    $root . '/quick',
    SaveCompatibilityManifest::fromArray('ichiloto/event-test', [
      'contentVersion' => 0,
      'migrations' => [],
      'aliases' => [],
      'tombstones' => [],
    ], 'Event test manifest'),
  );

  expect(fn() => $manager->save($scene, 1))->toThrow(ActiveEventSaveException::class)
    ->and(fn() => $manager->quickSave($scene))->toThrow(ActiveEventSaveException::class);
});

it('suspends for battle and resumes later commands with an optional result variable', function () {
  $root = sys_get_temp_dir() . '/event-battle-' . uniqid();
  mkdir($root . '/assets/Data', 0o777, true);
  file_put_contents($root . '/assets/Data/troops.php', <<<'PHP'
  <?php
  return [['name' => 'Technical Troop', 'enemies' => []]];
  PHP);
  $previousDirectory = getcwd();
  chdir($root);
  ConfigStore::put(EnemyStore::class, (new ReflectionClass(EnemyStore::class))->newInstanceWithoutConstructor());

  try {
    [$scene, $interpreter] = makeEventRuntime();
    $session = $interpreter->run([
      [
        'type' => 'start_battle',
        'troop' => 'Technical Troop',
        'resultVariable' => 'last_battle_result',
        'defeatPolicy' => 'continue',
      ],
      ['type' => 'set_switch', 'name' => 'after_battle', 'value' => true],
    ]);

    expect($session?->failureMessage)->toBeNull()
      ->and($session?->status)->toBe(EventExecutionStatus::SUSPENDED)
      ->and($scene->testSceneManager->battleCount)->toBe(1)
      ->and($scene->testSceneManager->lastTroop?->name)->toBe('Technical Troop')
      ->and($scene->testSceneManager->lastBattleSettings['event_defeat_policy'])->toBe('continue')
      ->and($scene->gameState->getSwitch('after_battle'))->toBeFalse();

    $scene->resumeEventAfterBattle(new BattleResult('Victory', []));
    $interpreter->update(0.016);

    expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($scene->gameState->getVariable('last_battle_result'))->toBe('victory')
      ->and($scene->gameState->getSwitch('after_battle'))->toBeTrue()
      ->and((new BattleResult('Defeat', []))->outcome())->toBe('defeat')
      ->and((new BattleResult('Retreat', []))->outcome())->toBe('escape');

    [$defeatScene, $defeatInterpreter] = makeEventRuntime();
    $defeatSession = $defeatInterpreter->run([
      [
        'type' => 'start_battle',
        'troop' => 'Technical Troop',
        'resultVariable' => 'scripted_defeat_result',
        'defeatPolicy' => 'continue',
      ],
      ['type' => 'set_switch', 'name' => 'continued_after_defeat', 'value' => true],
    ]);
    $defeatScene->resumeEventAfterBattle(new BattleResult('Defeat', []));
    $defeatInterpreter->update(0.016);

    expect($defeatSession?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($defeatScene->gameState->getVariable('scripted_defeat_result'))->toBe('defeat')
      ->and($defeatScene->gameState->getSwitch('continued_after_defeat'))->toBeTrue();
  } finally {
    ConfigStore::remove(EnemyStore::class);
    chdir($previousDirectory);
  }
});

it('runs the complete technical continuation route and saves its stable completion through IED1', function () {
  $root = sys_get_temp_dir() . '/event-api-route-' . uniqid();
  mkdir($root . '/assets/Data', 0o777, true);
  file_put_contents($root . '/assets/Data/troops.php', <<<'PHP'
  <?php
  return [['name' => 'Technical Troop', 'enemies' => []]];
  PHP);
  $previousDirectory = getcwd();
  chdir($root);
  putSceneAudioConfig(['save' => ['autosave' => true]]);
  ConfigStore::put(EnemyStore::class, (new ReflectionClass(EnemyStore::class))->newInstanceWithoutConstructor());

  try {
    [$scene, $interpreter, $presentation] = makeEventRuntime();
    $player = new EventTestPlayer(new Vector2(1, 1));
    $scene->installPlayer($player);
    $npcs = new NpcManager($scene);
    $npcs->configure([[
      'id' => 'technical-guide',
      'name' => 'Guide',
      'sprite' => 'G',
      'sprites' => ['north' => '^', 'south' => 'v', 'east' => '>', 'west' => '<'],
      'x' => 8,
      'y' => 4,
    ]]);
    $scene->installNpcManager($npcs);
    $manifest = SaveCompatibilityManifest::fromArray('ichiloto/event-api-route', [
      'contentVersion' => 0,
      'migrations' => [],
      'aliases' => [],
      'tombstones' => [],
    ], 'Event API route manifest');
    $saveManager = new SaveManager(new EventTestGame(), 'saves', 'saves/quick', $manifest);
    $trigger = new ScriptEventTrigger(
      new Rect(0, 0, 1, 1),
      [
        'mode' => 'action',
        'reusable' => false,
        'script' => [
          ['type' => 'text', 'name' => 'Tester', 'text' => 'Begin technical route.'],
          ['type' => 'move_route', 'subject' => 'player', 'secondsPerStep' => 0, 'steps' => [
            ['direction' => 'right', 'count' => 3],
          ]],
          ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'technical-guide', 'secondsPerStep' => 0, 'steps' => [
            ['direction' => 'left', 'count' => 1],
            ['direction' => 'down', 'count' => 1, 'faceOnly' => true],
          ]],
          ['type' => 'transfer', 'map' => 'map-b', 'x' => 2, 'y' => 3],
          ['type' => 'set_switch', 'name' => 'continued_after_transfer', 'value' => true],
          [
            'type' => 'start_battle',
            'troop' => 'Technical Troop',
            'resultVariable' => 'technical_battle_result',
            'defeatPolicy' => 'continue',
          ],
          ['type' => 'text', 'name' => 'Tester', 'text' => 'Technical route complete.'],
        ],
      ],
      sets: [['type' => 'event', 'name' => 'technical_sequence_complete']],
      mapId: 'map-a',
      marker: 'S',
    );
    $trigger->bind($scene->gameState, $scene->party);

    $session = $trigger->startSession($scene);

    expect($session?->status)->toBe(EventExecutionStatus::YIELDED)
      ->and($presentation->kind)->toBe('text')
      ->and($trigger->startSession($scene))->toBeNull()
      ->and(fn() => $saveManager->save($scene, 1))->toThrow(ActiveEventSaveException::class)
      ->and(fn() => $saveManager->quickSave($scene))->toThrow(ActiveEventSaveException::class);

    $presentation->resolveText();
    $interpreter->update(0.016);

    for ($step = 0; $step < 3; $step++) {
      $interpreter->update(0.016);
    }

    for ($step = 0; $step < 2; $step++) {
      $interpreter->update(0.016);
    }

    $npc = $npcs->findById('technical-guide');
    $camera = $scene->camera;
    assert($camera instanceof EventTestCamera);
    expect([$player->position->x, $player->position->y])->toBe([4.0, 1.0])
      ->and($player->facing)->toBe('right')
      ->and([$npc?->position->x, $npc?->position->y])->toBe([7.0, 4.0])
      ->and($npc?->sprite)->toBe('v')
      ->and($scene->restoredTiles)->toBe([[8, 4], [7, 4]])
      ->and($camera->renders)->toHaveCount(2)
      ->and($scene->currentMapId)->toBe('map-b')
      ->and($scene->hasDeferredAutoSave)->toBeTrue()
      ->and($scene->testSceneManager->eventTestSaveManager->autoSaveCount)->toBe(0);

    $interpreter->update(0.016);
    expect($session?->status)->toBe(EventExecutionStatus::SUSPENDED)
      ->and($scene->gameState->getSwitch('continued_after_transfer'))->toBeTrue()
      ->and($scene->testSceneManager->battleCount)->toBe(1);

    // Resolution systems have already applied rewards/progress before the
    // BattleResult returns; continuation must not run any of them again.
    $scene->party->credit(25);
    $scene->bestiary->recordDefeated('Technical Enemy');
    $scene->resumeEventAfterBattle(new BattleResult('Victory', []));
    // A duplicate return is ignored because the command is no longer suspended.
    $scene->resumeEventAfterBattle(new BattleResult('Victory', []));
    $interpreter->update(0.016);
    expect($presentation->kind)->toBe('text')
      ->and($scene->gameState->getVariable('technical_battle_result'))->toBe('victory')
      ->and($scene->gameState->hasStoryEvent('technical_sequence_complete'))->toBeFalse()
      ->and($scene->party->accountBalance)->toBe(25)
      ->and($scene->bestiary->timesDefeated('Technical Enemy'))->toBe(1);

    $presentation->resolveText();
    $interpreter->update(0.016);

    expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($scene->gameState->hasStoryEvent('technical_sequence_complete'))->toBeTrue()
      ->and(array_count_values($scene->gameState->storyEvents)['technical_sequence_complete'])->toBe(1)
      ->and($scene->gameState->isEventComplete('map-a', 'S'))->toBeTrue()
      ->and($trigger->startSession($scene))->toBeNull()
      ->and($scene->hasDeferredAutoSave)->toBeFalse()
      ->and($scene->testSceneManager->eventTestSaveManager->autoSaveCount)->toBe(1);

    $slot = $saveManager->save($scene, 1);
    $savedBytes = (string) file_get_contents($slot->path);
    $loaded = $saveManager->loadSlot(1);

    expect($savedBytes)->toStartWith('IED1')
      ->and($loaded->config->gameState['storyEvents'])->toContain('technical_sequence_complete')
      ->and($loaded->config->gameState['completedEvents'])->toHaveKey('map-a:S')
      ->and($loaded->config->gameState['variables']['technical_battle_result'])->toBe('victory');
  } finally {
    ConfigStore::remove(EnemyStore::class);
    ConfigStore::remove(ProjectConfig::class);
    chdir($previousDirectory);
  }
});

it('keeps normal defeat as game over unless continuation is explicitly authored', function () {
  $party = new Party();
  $troop = new Troop('Technical Troop');
  $battleScene = (new ReflectionClass(BattleScene::class))->newInstanceWithoutConstructor();
  new ReflectionProperty(BattleScene::class, 'config')->setValue(
    $battleScene,
    new BattleConfig($party, $troop),
  );

  expect($battleScene->continuesAfterDefeat())->toBeFalse();

  new ReflectionProperty(BattleScene::class, 'config')->setValue(
    $battleScene,
    new BattleConfig($party, $troop, settings: ['event_defeat_policy' => 'continue']),
  );

  expect($battleScene->continuesAfterDefeat())->toBeTrue();
});

it('retains exactly three active battlers from a larger travelling roster', function () {
  $party = new Party();

  foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $name) {
    $party->addMember(new Character($name, 1, new Stats()));
  }

  expect($party->members->toArray())->toHaveCount(5)
    ->and($party->battlers->toArray())->toHaveCount(3)
    ->and(array_map(
      static fn(Character $character): string => $character->name,
      $party->battlers->toArray(),
    ))->toBe(['One', 'Two', 'Three']);
});
