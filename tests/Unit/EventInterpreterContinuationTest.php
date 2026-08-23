<?php

use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Audio\AudioManager;
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
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicController;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicLibrary;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Enumerations\MovementEventType;
use Ichiloto\Engine\Events\MovementEvent;
use Ichiloto\Engine\Entities\Actions\RunCinematicAction;
use Ichiloto\Engine\Events\Triggers\CinematicEventTrigger;
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
use Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
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

class EventTestAudioManager extends AudioManager
{
  public function __construct(Game $game)
  {
    parent::__construct($game);
  }

  protected function createBackends(): array
  {
    return [];
  }
}

class EventTestGame extends Game
{
  public function __construct()
  {
    $this->audioManager = new EventTestAudioManager($this);
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
    $this->game = new EventTestGame();
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
    $this->player = null;
    $this->screen = new Rect(0, 0, 20, 10);
    $this->position = new Vector2(0, 0);
    $this->worldSpaceWidth = 100;
    $this->worldSpaceHeight = 60;
  }

  public function renderOnScreen(array $output, Vector2 $worldSpacePosition): void
  {
    $this->renders[] = [$output, clone $worldSpacePosition];
  }
}

class EventTestMapManager extends MapManager
{
  public array $blocked = [];
  public bool $shouldScroll = false;
  public int $renderCount = 0;

  public function __construct()
  {
  }

  public function canMoveTo(int $x, int $y, ?\Ichiloto\Engine\Events\Enumerations\CollisionType &$collisionType = null): bool
  {
    if (isset($this->blocked["{$x}:{$y}"])) {
      $collisionType = \Ichiloto\Engine\Events\Enumerations\CollisionType::SOLID;
      return false;
    }

    return true;
  }

  public function scrollMap(Player $player, Vector2 $moveDirection): bool
  {
    return $this->shouldScroll;
  }

  public function render(?int $x = null, ?int $y = null): void
  {
    $this->renderCount++;
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
  public int $transferCount = 0;
  public array $configuredTransferTransitions = [];
  public array $cinematicCoverAtTransfer = [];
  public bool $autoResumeTransfers = true;
  public int $cameraScrollRecompositions = 0;
  public bool $canRecomposeCameraScroll = false;

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
    $this->knowledge = new KnowledgeProgressService(new KnowledgeCatalog([
      'recordTypes' => ['test'],
      'subjects' => [[
        'id' => 'test.subject',
        'recordType' => 'test',
        'displayName' => 'Test Subject',
        'quickCard' => 'Test.',
        'observations' => ['noticed'],
      ]],
    ]));
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

  public function recomposeFieldAfterCameraScroll(): bool
  {
    $this->cameraScrollRecompositions++;
    return $this->canRecomposeCameraScroll;
  }

  public function installCinematicRuntime(): void
  {
    $this->cinematicStage = new CinematicStageManager($this);
    $this->cinematicPresentation = new CinematicPresentationManager($this);
    $this->cinematicController = new CinematicController($this);
  }

  public function deferAutoSaveForTesting(): void
  {
    $this->hasDeferredAutoSave = true;
  }

  public function transferPlayer(Location $location, bool $useConfiguredTransition = true): void
  {
    $this->transferCount++;
    $this->configuredTransferTransitions[] = $useConfiguredTransition;
    $this->cinematicCoverAtTransfer[] = $this->cinematicPresentation?->hasTransitionCover() ?? false;
    $this->cinematicStage?->clear();
    $this->currentMapId = $location->mapFilename;

    if ($this->autoResumeTransfers) {
      $this->finalizePlayerTransfer();
    } else {
      $this->autoSave();
    }
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

/**
 * Creates one disposable project containing a first-class Cinematic asset.
 *
 * @param array<int, array<string, mixed>> $commands
 * @param array<string, mixed> $definitionOverrides
 */
function makeCinematicTriggerProject(
  string $id,
  array $commands,
  array $definitionOverrides = [],
): string
{
  $root = sys_get_temp_dir() . '/cinematic-trigger-' . uniqid();
  $assetRoot = $root . '/assets/Cutscenes/Cinematics/' . $id;
  mkdir($assetRoot, 0o777, true);
  $definition = array_replace([
    'id' => $id,
    'name' => 'Cinematic Trigger Fixture',
  ], $definitionOverrides);
  file_put_contents(
    $assetRoot . '/' . $id . '.data.php',
    "<?php\n\nreturn " . var_export($definition, true) . ";\n",
  );
  file_put_contents(
    $assetRoot . '/' . $id . '.script.php',
    "<?php\n\nreturn " . var_export($commands, true) . ";\n",
  );

  return $root;
}

it('grants catalog items by name and requested quantity', function () {
  $store = (new ReflectionClass(ItemStore::class))->newInstanceWithoutConstructor();
  $store->set('Test Blade', new Ichiloto\Engine\Entities\Inventory\Weapons\Weapon(
    'Test Blade',
    'A test weapon.',
    '/',
    10,
  ));
  ConfigStore::put(ItemStore::class, $store);

  try {
    [$scene, $interpreter] = makeEventRuntime();
    $session = $interpreter->run([
      ['type' => 'give_item', 'item' => 'Test Blade', 'quantity' => 2],
      ['type' => 'record_event', 'name' => 'grant_complete'],
    ], 'item-grant');

    expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($scene->party->inventory->getQuantityByName('Test Blade'))->toBe(2)
      ->and($scene->gameState->hasStoryEvent('grant_complete'))->toBeTrue();
  } finally {
    ConfigStore::remove(ItemStore::class);
  }
});

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

it('routes generic knowledge commands through the shared progress service and fails closed when malformed', function () {
  [$scene, $interpreter] = makeEventRuntime();

  $completed = $interpreter->run([
    ['type' => 'knowledge', 'operation' => 'discover', 'subject' => 'test.subject', 'source' => 'story.event'],
    ['type' => 'knowledge', 'operation' => 'observe', 'subject' => 'test.subject', 'observation' => 'noticed', 'source' => 'story.event'],
  ], 'knowledge-success');

  expect($completed?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->knowledge->progress->depth('test.subject')->name)->toBe('OBSERVED');

  $failed = $interpreter->run([
    ['type' => 'knowledge', 'operation' => 'invent_truth', 'subject' => 'test.subject'],
    ['type' => 'record_event', 'name' => 'must_not_run'],
  ], 'knowledge-failure');

  expect($failed?->status)->toBe(EventExecutionStatus::FAILED)
    ->and($failed?->failureMessage)->toContain('Unknown knowledge operation')
    ->and($scene->gameState->hasStoryEvent('must_not_run'))->toBeFalse();
});

it('recovers every travelling member without changing roster order', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $first = new Character('First', 0, new Stats(currentHp: 5, currentMp: 1, currentAp: 2, totalHp: 100, totalMp: 20, totalAp: 10));
  $reserve = new Character('Reserve', 0, new Stats(currentHp: 1, currentMp: 0, currentAp: 0, totalHp: 80, totalMp: 30, totalAp: 6));
  $scene->party->addMember($first);
  $scene->party->addMember($reserve);

  $session = $interpreter->run([['type' => 'recover_party']], 'recovery');

  expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and([$first->stats->currentHp, $first->stats->currentMp, $first->stats->currentAp])->toBe([100, 20, 10])
    ->and([$reserve->stats->currentHp, $reserve->stats->currentMp, $reserve->stats->currentAp])->toBe([80, 30, 6])
    ->and(array_map(static fn(Character $member): string => $member->name, $scene->party->members->toArray()))
    ->toBe(['First', 'Reserve']);
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

it('keeps cinematic narration visible for the global reading-time floor', function () {
  ConfigStore::remove(ProjectConfig::class);
  [$scene, $interpreter] = makeEventRuntime();
  $scene->installCinematicRuntime();

  $session = $interpreter->run([[
    'type' => 'narration',
    'text' => 'Wind enters through all four restored road channels. The Stone sounds one low tone.',
    'seconds' => 1.2,
  ]]);

  expect($session?->status)->toBe(EventExecutionStatus::YIELDED)
    ->and($session?->pendingState['kind'])->toBe('presentation')
    ->and(round($session?->pendingState['remainingSeconds'], 6))->toBe(5.666667);

  $interpreter->update(1.2);

  expect($session?->status)->toBe(EventExecutionStatus::YIELDED);
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

it('runs an authored choice cancellation arm without selecting an option', function () {
  [$scene, $interpreter, $presentation] = makeEventRuntime();

  $session = $interpreter->run([
    [
      'type' => 'choice',
      'prompt' => 'Submit?',
      'options' => [[
        'text' => 'Submit',
        'then' => [['type' => 'set_switch', 'name' => 'submitted', 'value' => true]],
      ]],
      'cancel' => [['type' => 'set_switch', 'name' => 'cancelled', 'value' => true]],
    ],
    ['type' => 'set_switch', 'name' => 'continued', 'value' => true],
  ]);

  $presentation->resolveChoice(-1);
  $interpreter->update(0.016);

  expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->gameState->getSwitch('submitted'))->toBeFalse()
    ->and($scene->gameState->getSwitch('cancelled'))->toBeTrue()
    ->and($scene->gameState->getSwitch('continued'))->toBeTrue();
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
    ->and($player->availableAction)->toBeNull();

  $player->dispatchMovement(new Vector2(1, 0), new Vector2(2, 0));

  expect($player->availableAction)->toBeNull();
});

it('renders an authored event cue only while its trigger is available and incomplete', function () {
  [$scene] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(0, 0));
  $scene->installPlayer($player);
  $trigger = new ScriptEventTrigger(
    new Rect(4, 6, 3, 3),
    ['mode' => 'action', 'reusable' => false, 'script' => [['type' => 'wait', 'seconds' => 0.1]]],
    conditions: [['type' => 'event', 'name' => 'station_ready']],
    cue: ['symbol' => '!', 'color' => 'bright-yellow'],
  );
  $trigger->bind($scene->gameState, $scene->party);
  $player->addTrigger($trigger);

  $player->renderEventCues();
  expect($scene->camera->renders)->toBe([]);

  $scene->gameState->recordStoryEvent('station_ready');
  $player->renderEventCues();

  expect($scene->camera->renders)->toHaveCount(1)
    ->and($scene->camera->renders[0][0])->toBe(['<fg=bright-yellow>!</>'])
    ->and([$scene->camera->renders[0][1]->x, $scene->camera->renders[0][1]->y])->toBe([5.0, 7.0]);

  $trigger->complete();
  $player->renderEventCues();
  expect($scene->camera->renders)->toHaveCount(1);
});

it('delegates a camera-scrolling step to the complete field compositor', function () {
  [$scene] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(0, 0));
  $scene->installPlayer($player);
  $scene->mapManager->shouldScroll = true;
  $scene->canRecomposeCameraScroll = true;

  expect($player->tryFieldMove(Vector2::down(), $scene->camera))->toBeTrue()
    ->and($scene->cameraScrollRecompositions)->toBe(1)
    ->and($scene->mapManager->renderCount)->toBe(0);
});

it('restores authored event cues after an ordinary player step', function () {
  [$scene] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(0, 0));
  $scene->installPlayer($player);
  $trigger = new ScriptEventTrigger(
    new Rect(4, 6, 1, 1),
    ['mode' => 'action', 'reusable' => true, 'script' => [['type' => 'wait', 'seconds' => 0.1]]],
    cue: ['symbol' => '!', 'color' => 'bright-yellow'],
  );
  $trigger->bind($scene->gameState, $scene->party);
  $player->addTrigger($trigger);

  expect($player->tryFieldMove(Vector2::right(), $scene->camera))->toBeTrue()
    ->and($scene->camera->renders)->toHaveCount(1)
    ->and($scene->camera->renders[0][0])->toBe(['<fg=bright-yellow>!</>']);
});

it('preserves event cues in the no-FieldState camera-scroll fallback', function () {
  [$scene] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(0, 0));
  $scene->installPlayer($player);
  $scene->mapManager->shouldScroll = true;
  $trigger = new ScriptEventTrigger(
    new Rect(4, 6, 1, 1),
    ['mode' => 'action', 'reusable' => true, 'script' => [['type' => 'wait', 'seconds' => 0.1]]],
    cue: ['symbol' => '!', 'color' => 'bright-yellow'],
  );
  $trigger->bind($scene->gameState, $scene->party);
  $player->addTrigger($trigger);

  expect($player->tryFieldMove(Vector2::down(), $scene->camera))->toBeTrue()
    ->and($scene->cameraScrollRecompositions)->toBe(1)
    ->and($scene->mapManager->renderCount)->toBe(1)
    ->and($scene->camera->renders)->toHaveCount(1);
});

it('can gate a cue without disabling its trigger interaction', function () {
  [$scene] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(0, 0));
  $scene->installPlayer($player);
  $trigger = new ScriptEventTrigger(
    new Rect(4, 6, 3, 3),
    ['mode' => 'action', 'reusable' => true, 'script' => [['type' => 'wait', 'seconds' => 0.1]]],
    conditions: [['type' => 'event', 'name' => 'station_online']],
    cue: [
      'symbol' => '!',
      'color' => 'bright-yellow',
      'conditions' => [['type' => 'event', 'name' => 'response_ready']],
    ],
  );
  $trigger->bind($scene->gameState, $scene->party);
  $player->addTrigger($trigger);
  $scene->gameState->recordStoryEvent('station_online');

  expect($trigger->isAvailable())->toBeTrue()
    ->and($trigger->shouldRenderCue())->toBeFalse();

  $player->dispatchMovement(new Vector2(0, 0), new Vector2(4, 6));
  expect($player->availableAction)->not->toBeNull();

  $scene->gameState->recordStoryEvent('response_ready');
  $player->renderEventCues();

  expect($trigger->shouldRenderCue())->toBeTrue()
    ->and($scene->camera->renders)->toHaveCount(1)
    ->and($scene->camera->renders[0][0])->toBe(['<fg=bright-yellow>!</>']);
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

it('runs destination automatic triggers after the transfer session finishes', function () {
  putSceneAudioConfig([]);
  [$scene, $interpreter] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(2, 3));
  $scene->installPlayer($player);
  $trigger = new ScriptEventTrigger(
    new Rect(2, 3, 1, 1),
    ['mode' => 'auto', 'reusable' => false, 'script' => [
      ['type' => 'record_event', 'name' => 'arrival_event_started'],
    ]],
    mapId: 'map-b',
    marker: 'B',
  );
  $trigger->bind($scene->gameState, $scene->party);
  $player->addTrigger($trigger);

  $session = $interpreter->run([
    ['type' => 'transfer', 'map' => 'map-b', 'x' => 2, 'y' => 3],
    ['type' => 'set_switch', 'name' => 'transfer_session_finished', 'value' => true],
  ]);

  expect($session?->status)->toBe(EventExecutionStatus::RUNNING)
    ->and($scene->gameState->hasStoryEvent('arrival_event_started'))->toBeFalse();

  $interpreter->update(0.016);

  expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->gameState->getSwitch('transfer_session_finished'))->toBeTrue()
    ->and($scene->gameState->hasStoryEvent('arrival_event_started'))->toBeTrue()
    ->and($scene->gameState->isEventComplete('map-b', 'B'))->toBeTrue();

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

it('advances nested parallel lanes deterministically with lane-local pending state', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $session = $interpreter->run([[
    'type' => 'sequence',
    'commands' => [[
      'type' => 'parallel',
      'lanes' => [
        ['id' => 'first', 'commands' => [
          ['type' => 'wait', 'seconds' => 0.1],
          ['type' => 'record_event', 'name' => 'parallel_first'],
        ]],
        ['id' => 'second', 'commands' => [
          ['type' => 'wait', 'seconds' => 0.1],
          ['type' => 'record_event', 'name' => 'parallel_second'],
        ]],
        ['id' => 'slower', 'commands' => [
          ['type' => 'wait', 'seconds' => 0.2],
          ['type' => 'record_event', 'name' => 'parallel_slower'],
        ]],
      ],
    ]],
  ]], 'parallel-order');

  $interpreter->update(0.0);
  $interpreter->update(0.1);

  expect($session?->status)->toBe(EventExecutionStatus::YIELDED)
    ->and($scene->gameState->storyEvents)->toBe(['parallel_first', 'parallel_second']);

  $interpreter->update(0.1);

  expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->gameState->storyEvents)->toBe([
      'parallel_first',
      'parallel_second',
      'parallel_slower',
    ]);
});

it('handles empty sequential blocks and fails malformed parallel blocks when validation is skipped', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $emptySequence = $interpreter->run([
    ['type' => 'sequence', 'commands' => []],
    ['type' => 'record_event', 'name' => 'empty_sequence_completed'],
  ], 'empty-sequence');

  expect($emptySequence?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->gameState->hasStoryEvent('empty_sequence_completed'))->toBeTrue();

  $emptyParallel = $interpreter->run([[
    'type' => 'parallel',
    'lanes' => [],
  ]], 'empty-parallel');

  expect($emptyParallel?->status)->toBe(EventExecutionStatus::FAILED)
    ->and($emptyParallel?->failureMessage)->toContain('non-empty list');

  $duplicateLane = $interpreter->run([[
    'type' => 'parallel',
    'lanes' => [
      ['id' => 'route', 'commands' => [['type' => 'wait', 'seconds' => 0.1]]],
      ['id' => 'route', 'commands' => [['type' => 'wait', 'seconds' => 0.1]]],
    ],
  ]], 'duplicate-parallel-lane');

  expect($duplicateLane?->status)->toBe(EventExecutionStatus::FAILED)
    ->and($duplicateLane?->failureMessage)->toContain('duplicate lane id "route"');
});

it('cancels sibling lane operations when one parallel lane fails', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $scene->installPlayer(new EventTestPlayer(new Vector2(1, 1)));
  $scene->installCinematicRuntime();
  $scene->cinematicStage?->add(['id' => 'runner', 'sprite' => '@', 'x' => 2, 'y' => 2]);

  $session = $interpreter->run([[
    'type' => 'parallel',
    'lanes' => [
      ['id' => 'failure', 'commands' => [[
        'type' => 'camera',
        'operation' => 'focus',
        'target' => ['kind' => 'staged_actor', 'id' => 'missing'],
      ]]],
      ['id' => 'movement', 'commands' => [
        [
          'type' => 'move_route',
          'subject' => 'staged_actor',
          'actorId' => 'runner',
          'secondsPerStep' => 0.1,
          'steps' => [['direction' => 'right', 'count' => 3]],
        ],
        ['type' => 'record_event', 'name' => 'cancelled_lane_must_not_complete'],
      ]],
    ],
  ]], 'parallel-failure');
  $interpreter->update(0.0);

  expect($session?->status)->toBe(EventExecutionStatus::FAILED)
    ->and($session?->failureMessage)->toContain('parallel[failure]')
    ->and($scene->gameState->hasStoryEvent('cancelled_lane_must_not_complete'))->toBeFalse()
    ->and($scene->cinematicStage?->require('runner')->position->x)->toBe(2.0);
});

it('continues staged movement while a parallel dialogue lane owns presentation', function () {
  [$scene, $interpreter, $presentation] = makeEventRuntime();
  $scene->installPlayer(new EventTestPlayer(new Vector2(0, 0)));
  $scene->installCinematicRuntime();
  $scene->cinematicStage?->add(['id' => 'runner', 'sprite' => '@', 'x' => 2, 'y' => 2]);

  $session = $interpreter->run([[
    'type' => 'parallel',
    'lanes' => [
      ['id' => 'dialogue', 'commands' => [[
        'type' => 'text',
        'name' => 'Guide',
        'text' => 'Keep moving.',
      ]]],
      ['id' => 'movement', 'commands' => [[
        'type' => 'move_route',
        'subject' => 'staged_actor',
        'actorId' => 'runner',
        'secondsPerStep' => 0.1,
        'steps' => [['direction' => 'right', 'count' => 2]],
      ]]],
    ],
  ]], 'dialogue-motion');

  $interpreter->update(0.1);
  $interpreter->update(0.1);

  expect($presentation->kind)->toBe('text')
    ->and($scene->cinematicStage?->require('runner')->position->x)->toBe(3.0)
    ->and($session?->status)->toBe(EventExecutionStatus::YIELDED);

  $interpreter->update(0.1);
  $presentation->resolveText();
  $interpreter->update(0.1);

  expect($scene->cinematicStage?->require('runner')->position->x)->toBe(4.0)
    ->and($session?->status)->toBe(EventExecutionStatus::COMPLETED);
});

it('runs the original cinematic fixture to deterministic cleanup and save availability', function () {
  $projectRoot = dirname(__DIR__) . '/Fixtures/Projects/CinematicAcceptance';
  $previousDirectory = getcwd();
  chdir($projectRoot);
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => false]]);
  ConfigStore::put(PlaySettings::class, new SceneAudioConfigStub([
    'screen' => ['width' => 20, 'height' => 10],
  ]));

  try {
    [$scene, $interpreter] = makeEventRuntime();
    $scene->installPlayer(new EventTestPlayer(new Vector2(1, 1)));
    $scene->installCinematicRuntime();
    $definition = (new CinematicLibrary())->load('sky-caravan');
    $session = $scene->cinematicController?->start($definition);
    expect($session?->status)->toBe(EventExecutionStatus::YIELDED)
      ->and($scene->hasUnstableEventSession())->toBeTrue()
      ->and($scene->cinematicPresentation?->hasTransitionCover())->toBeTrue()
      ->and($scene->cinematicStage?->all())->toHaveCount(3);

    for ($tick = 0; $tick < 60 && $scene->hasUnstableEventSession(); $tick++) {
      $interpreter->update(0.1);
    }

    expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($scene->currentMapId)->toBe('cinematic/skyfield-dawn')
      ->and($scene->transferCount)->toBe(1)
      ->and([$scene->player?->position->x, $scene->player?->position->y])->toBe([4.0, 4.0])
      ->and($scene->gameState->getSwitch('sky_caravan_arrived'))->toBeTrue()
      ->and(array_count_values($scene->gameState->storyEvents)['sky_caravan_finalized'])->toBe(1)
      ->and(array_count_values($scene->gameState->storyEvents)['cinematic:sky-caravan:completed'])->toBe(1)
      ->and($scene->cinematicStage?->all())->toBe([])
      ->and($scene->cinematicPresentation?->hasTransitionCover())->toBeFalse()
      ->and($scene->camera->followsPlayer)->toBeTrue()
      ->and($scene->cinematicController?->active())->toBeNull()
      ->and($scene->hasUnstableEventSession())->toBeFalse();

    $saveRoot = sys_get_temp_dir() . '/cinematic-save-' . uniqid();
    $saveManager = new SaveManager(
      new EventTestGame(),
      $saveRoot,
      $saveRoot . '/quick',
      SaveCompatibilityManifest::fromArray('ichiloto/cinematic-fixture', [
        'contentVersion' => 0,
        'migrations' => [],
        'aliases' => [],
        'tombstones' => [],
      ], 'Cinematic fixture manifest'),
    );
    expect(file_get_contents($saveManager->save($scene, 1)->path))->toStartWith('IED1');
  } finally {
    ConfigStore::remove(ProjectConfig::class);
    ConfigStore::remove(PlaySettings::class);
    chdir($previousDirectory);
  }
});

it('uses the same authored finalizer for skips before and after transfer', function (int|string $skipBoundary) {
  $projectRoot = dirname(__DIR__) . '/Fixtures/Projects/CinematicAcceptance';
  $previousDirectory = getcwd();
  chdir($projectRoot);
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => true]]);
  ConfigStore::put(PlaySettings::class, new SceneAudioConfigStub([
    'screen' => ['width' => 20, 'height' => 10],
  ]));

  try {
    [$scene, $interpreter] = makeEventRuntime();
    $scene->installPlayer(new EventTestPlayer(new Vector2(1, 1)));
    $scene->installCinematicRuntime();
    $session = $scene->cinematicController?->start((new CinematicLibrary())->load('sky-caravan'));

    if ($skipBoundary === 'after_transfer') {
      for ($tick = 0; $tick < 30
        && $scene->hasUnstableEventSession()
        && $scene->currentMapId !== 'cinematic/skyfield-dawn';
        $tick++
      ) {
        $interpreter->update(0.1);
      }
    } else {
      for ($tick = 0; $tick < $skipBoundary && $scene->hasUnstableEventSession(); $tick++) {
        $interpreter->update(0.1);
      }
    }

    expect($scene->skipCinematic())->toBeTrue();

    for ($tick = 0; $tick < 20 && $scene->hasUnstableEventSession(); $tick++) {
      $interpreter->update(0.1);
    }

    expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($scene->currentMapId)->toBe('cinematic/skyfield-dawn')
      ->and($scene->transferCount)->toBe(1)
      ->and([$scene->player?->position->x, $scene->player?->position->y])->toBe([4.0, 4.0])
      ->and($scene->gameState->getSwitch('sky_caravan_arrived'))->toBeTrue()
      ->and(array_count_values($scene->gameState->storyEvents)['sky_caravan_finalized'])->toBe(1)
      ->and(array_count_values($scene->gameState->storyEvents)['cinematic:sky-caravan:completed'])->toBe(1)
      ->and($scene->cinematicStage?->all())->toBe([])
      ->and($scene->camera->followsPlayer)->toBeTrue()
      ->and($scene->hasUnstableEventSession())->toBeFalse();
  } finally {
    ConfigStore::remove(ProjectConfig::class);
    ConfigStore::remove(PlaySettings::class);
    chdir($previousDirectory);
  }
})->with([
  'before movement' => 0,
  'during camera, movement, and narration' => 3,
  'after transfer' => 'after_transfer',
]);

it('launches a stable cinematic id from an action trigger and completes after transfer', function () {
  $projectRoot = dirname(__DIR__) . '/Fixtures/Projects/CinematicAcceptance';
  $previousDirectory = getcwd();
  chdir($projectRoot);
  putSceneAudioConfig([
    'accessibility' => ['reducedMotion' => false],
    'save' => ['autosave' => true],
  ]);
  ConfigStore::put(PlaySettings::class, new SceneAudioConfigStub([
    'screen' => ['width' => 20, 'height' => 10],
  ]));

  try {
    [$scene, $interpreter] = makeEventRuntime();
    $player = new EventTestPlayer(new Vector2(0, 0));
    $scene->installPlayer($player);
    $scene->installCinematicRuntime();
    $trigger = new CinematicEventTrigger(
      new Rect(1, 0, 1, 1),
      ['mode' => 'action', 'reusable' => false, 'cinematicId' => 'sky-caravan'],
      sets: [['type' => 'variable', 'name' => 'trigger_completions', 'op' => 'add', 'value' => 1]],
      mapId: 'map-a',
      marker: 'K',
    );
    $trigger->bind($scene->gameState, $scene->party);
    $player->addTrigger($trigger);
    $player->dispatchMovement(new Vector2(0, 0), new Vector2(1, 0));

    expect($player->availableAction)->toBeInstanceOf(RunCinematicAction::class);
    $player->interact();
    $session = $interpreter->activeSession();
    $saveRoot = sys_get_temp_dir() . '/cinematic-trigger-save-' . uniqid();
    $saveManager = new SaveManager(
      new EventTestGame(),
      $saveRoot,
      $saveRoot . '/quick',
      SaveCompatibilityManifest::fromArray('ichiloto/cinematic-trigger', [
        'contentVersion' => 0,
        'migrations' => [],
        'aliases' => [],
        'tombstones' => [],
      ], 'Cinematic trigger save guard'),
    );

    expect($session?->status)->toBe(EventExecutionStatus::YIELDED)
      ->and($trigger->sessionIsActive)->toBeTrue()
      ->and($trigger->startSession($scene))->toBeNull()
      ->and(fn() => $saveManager->save($scene, 1))->toThrow(ActiveEventSaveException::class)
      ->and(fn() => $saveManager->quickSave($scene))->toThrow(ActiveEventSaveException::class);

    for ($tick = 0; $tick < 60 && $scene->hasUnstableEventSession(); $tick++) {
      $interpreter->update(0.1);
    }

    expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($scene->currentMapId)->toBe('cinematic/skyfield-dawn')
      ->and($scene->transferCount)->toBe(1)
      ->and($scene->testSceneManager->eventTestSaveManager->autoSaveCount)->toBe(1)
      ->and($trigger->sessionIsActive)->toBeFalse()
      ->and($trigger->isComplete)->toBeTrue()
      ->and($scene->gameState->isEventComplete('map-a', 'K'))->toBeTrue()
      ->and($scene->gameState->getVariable('trigger_completions'))->toBe(1)
      ->and(array_count_values($scene->gameState->storyEvents)['cinematic:sky-caravan:completed'])->toBe(1)
      ->and($player->availableAction)->toBeNull()
      ->and($trigger->startSession($scene))->toBeNull()
      ->and($scene->gameState->getVariable('trigger_completions'))->toBe(1);
  } finally {
    ConfigStore::remove(ProjectConfig::class);
    ConfigStore::remove(PlaySettings::class);
    chdir($previousDirectory);
  }
});

it('completes reusable automatic cinematic triggers through the authored skip path', function () {
  $projectRoot = makeCinematicTriggerProject(
    'trigger-skippable',
    [['type' => 'wait', 'seconds' => 5.0]],
    [
      'skip' => ['policy' => 'authored'],
      'finalizer' => [
        ['type' => 'set_switch', 'name' => 'trigger_skip_finalized', 'value' => true],
        ['type' => 'record_event', 'name' => 'trigger_skip_finalized'],
      ],
    ],
  );
  $previousDirectory = getcwd();
  chdir($projectRoot);
  putSceneAudioConfig([]);

  try {
    [$scene] = makeEventRuntime();
    $player = new EventTestPlayer(new Vector2(2, 2));
    $scene->installPlayer($player);
    $scene->installCinematicRuntime();
    $trigger = new CinematicEventTrigger(
      new Rect(2, 2, 1, 1),
      ['mode' => 'auto', 'reusable' => true, 'cinematicId' => 'trigger-skippable'],
      sets: [['type' => 'variable', 'name' => 'reusable_trigger_count', 'op' => 'add', 'value' => 1]],
      mapId: 'map-a',
      marker: 'S',
    );
    $trigger->bind($scene->gameState, $scene->party);
    $player->addTrigger($trigger);
    $player->evaluateAutomaticTriggersAtCurrentPosition();

    expect($trigger->sessionIsActive)->toBeTrue()
      ->and($scene->skipCinematic())->toBeTrue()
      ->and($trigger->sessionIsActive)->toBeFalse()
      ->and($trigger->isComplete)->toBeFalse()
      ->and($scene->gameState->isEventComplete('map-a', 'S'))->toBeFalse()
      ->and($scene->gameState->getSwitch('trigger_skip_finalized'))->toBeTrue()
      ->and($scene->gameState->getVariable('reusable_trigger_count'))->toBe(1)
      ->and(array_count_values($scene->gameState->storyEvents)['cinematic:trigger-skippable:completed'])->toBe(1);

    expect($trigger->startSession($scene))->not->toBeNull()
      ->and($scene->skipCinematic())->toBeTrue()
      ->and($scene->gameState->getVariable('reusable_trigger_count'))->toBe(2)
      ->and(array_count_values($scene->gameState->storyEvents)['cinematic:trigger-skippable:completed'])->toBe(1);
  } finally {
    ConfigStore::remove(ProjectConfig::class);
    chdir($previousDirectory);
  }
});

it('preserves field conditions, blocked movement and cues for cinematic triggers', function () {
  [$scene] = makeEventRuntime();
  $player = new EventTestPlayer(new Vector2(0, 0));
  $scene->installPlayer($player);
  $trigger = new CinematicEventTrigger(
    new Rect(1, 0, 1, 1),
    ['mode' => 'action', 'reusable' => true, 'cinematicId' => 'stable-cinematic'],
    conditions: [['type' => 'switch', 'name' => 'cinematic_ready']],
    whenBlocked: 'The presentation is not ready.',
    cue: ['symbol' => '!', 'color' => 'bright-yellow'],
  );
  $trigger->bind($scene->gameState, $scene->party);
  $player->addTrigger($trigger);

  $player->renderEventCues();
  expect($scene->camera->renders)->toBe([])
    ->and($player->tryFieldMove(Vector2::right(), $scene->camera))->toBeFalse()
    ->and($player->blockedMessages)->toBe(['The presentation is not ready.']);

  $scene->gameState->setSwitch('cinematic_ready', true);
  $player->renderEventCues();

  expect($scene->camera->renders)->toHaveCount(1)
    ->and($scene->camera->renders[0][0])->toBe(['<fg=bright-yellow>!</>'])
    ->and($player->tryFieldMove(Vector2::right(), $scene->camera))->toBeTrue()
    ->and($player->availableAction)->toBeInstanceOf(RunCinematicAction::class);

  $scene->gameState->setSwitch('cinematic_ready', false);
  $player->interact();

  expect($scene->hasUnstableEventSession())->toBeFalse()
    ->and($player->availableAction)->toBeInstanceOf(RunCinematicAction::class);
});

it('fails cinematic triggers closed without completion writes and permits a retry', function () {
  $projectRoot = makeCinematicTriggerProject('trigger-failure', [[
    'type' => 'camera',
    'operation' => 'focus',
    'target' => ['kind' => 'staged_actor', 'id' => 'missing-actor'],
  ]]);
  $previousDirectory = getcwd();
  chdir($projectRoot);

  try {
    [$scene] = makeEventRuntime();
    $scene->installPlayer(new EventTestPlayer(new Vector2(0, 0)));
    $scene->installCinematicRuntime();
    $trigger = new CinematicEventTrigger(
      new Rect(0, 0, 1, 1),
      ['mode' => 'auto', 'reusable' => false, 'cinematicId' => 'trigger-failure'],
      sets: [
        ['type' => 'event', 'name' => 'failed_trigger_must_not_complete'],
        ['type' => 'variable', 'name' => 'failed_trigger_count', 'op' => 'add', 'value' => 1],
      ],
      mapId: 'map-a',
      marker: 'F',
    );
    $trigger->bind($scene->gameState, $scene->party);
    $first = $trigger->startSession($scene);

    expect($first?->status)->toBe(EventExecutionStatus::FAILED)
      ->and($trigger->sessionIsActive)->toBeFalse()
      ->and($trigger->isComplete)->toBeFalse()
      ->and($scene->gameState->isEventComplete('map-a', 'F'))->toBeFalse()
      ->and($scene->gameState->hasStoryEvent('failed_trigger_must_not_complete'))->toBeFalse()
      ->and($scene->gameState->hasStoryEvent('cinematic:trigger-failure:completed'))->toBeFalse()
      ->and($scene->gameState->getVariable('failed_trigger_count'))->toBe(0)
      ->and($scene->cinematicController?->active())->toBeNull()
      ->and($scene->hasUnstableEventSession())->toBeFalse();

    $retry = $trigger->startSession($scene);
    expect($retry)->not->toBeNull()
      ->and($retry)->not->toBe($first)
      ->and($retry?->status)->toBe(EventExecutionStatus::FAILED)
      ->and($scene->gameState->getVariable('failed_trigger_count'))->toBe(0);
  } finally {
    chdir($previousDirectory);
  }
});

it('rejects missing malformed unresolved and concurrently owned cinematic launches', function () {
  $projectRoot = makeCinematicTriggerProject('trigger-after-wait', [['type' => 'wait', 'seconds' => 0.1]]);
  $malformedAssetRoot = $projectRoot . '/assets/Cutscenes/Cinematics/malformed-asset';
  mkdir($malformedAssetRoot, 0o777, true);
  file_put_contents($malformedAssetRoot . '/malformed-asset.data.php', "<?php\n\nreturn 'not a definition';\n");
  file_put_contents($malformedAssetRoot . '/malformed-asset.script.php', "<?php\n\nreturn [];\n");
  $previousDirectory = getcwd();
  chdir($projectRoot);

  try {
    [$scene, $interpreter] = makeEventRuntime();
    $scene->installPlayer(new EventTestPlayer(new Vector2(0, 0)));
    $scene->installCinematicRuntime();
    $missing = new CinematicEventTrigger(new Rect(0, 0, 1, 1), ['mode' => 'auto']);
    $malformed = new CinematicEventTrigger(
      new Rect(0, 0, 1, 1),
      ['mode' => 'auto', 'cinematicId' => 'Not A Stable ID'],
    );
    $unresolved = new CinematicEventTrigger(
      new Rect(0, 0, 1, 1),
      ['mode' => 'auto', 'cinematicId' => 'not-installed'],
    );
    $malformedAsset = new CinematicEventTrigger(
      new Rect(0, 0, 1, 1),
      ['mode' => 'auto', 'cinematicId' => 'malformed-asset'],
    );

    expect($missing->startSession($scene))->toBeNull()
      ->and($malformed->startSession($scene))->toBeNull()
      ->and($unresolved->startSession($scene))->toBeNull()
      ->and($malformedAsset->startSession($scene))->toBeNull()
      ->and($scene->hasUnstableEventSession())->toBeFalse();

    $ordinary = $interpreter->run([['type' => 'wait', 'seconds' => 0.1]], 'ordinary-owner');
    $trigger = new CinematicEventTrigger(
      new Rect(0, 0, 1, 1),
      ['mode' => 'auto', 'cinematicId' => 'trigger-after-wait'],
    );
    expect($ordinary?->status)->toBe(EventExecutionStatus::YIELDED)
      ->and($trigger->startSession($scene))->toBeNull()
      ->and($trigger->sessionIsActive)->toBeFalse();

    $interpreter->update(0.1);
    $cinematic = $trigger->startSession($scene);
    expect($ordinary?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($cinematic)->not->toBeNull()
      ->and($trigger->startSession($scene))->toBeNull();
    $interpreter->update(0.1);

    expect($cinematic?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($trigger->sessionIsActive)->toBeFalse();
  } finally {
    chdir($previousDirectory);
  }
});

it('skips safely during dialogue and rejects authored skipping across a battle boundary', function () {
  [$scene, $interpreter, $presentation] = makeEventRuntime();
  $scene->installPlayer(new EventTestPlayer(new Vector2(1, 1)));
  $scene->installCinematicRuntime();
  $dialogue = CinematicDefinition::fromArrays([
    'id' => 'dialogue-skip',
    'name' => 'Dialogue Skip',
    'skip' => ['policy' => 'authored'],
    'finalizer' => [
      ['type' => 'set_switch', 'name' => 'dialogue_finalized', 'value' => true],
      ['type' => 'camera', 'operation' => 'attach'],
    ],
  ], [
    ['type' => 'text', 'name' => 'Guide', 'text' => 'A cancellable cinematic line.'],
    ['type' => 'set_switch', 'name' => 'dialogue_should_not_continue', 'value' => true],
  ]);
  $dialogueSession = $scene->cinematicController?->start($dialogue);

  $dialogueSkipped = $scene->skipCinematic();

  expect($presentation->kind)->toBeNull()
    ->and($dialogueSkipped)->toBeTrue()
    ->and($dialogueSession?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->gameState->getSwitch('dialogue_finalized'))->toBeTrue()
    ->and($scene->gameState->getSwitch('dialogue_should_not_continue'))->toBeFalse();

  expect(fn() => CinematicDefinition::fromArrays([
      'id' => 'battle-boundary',
      'name' => 'Battle Boundary',
      'skip' => ['policy' => 'authored'],
      'finalizer' => [['type' => 'set_switch', 'name' => 'battle_finalized', 'value' => true]],
    ], [[
      'type' => 'start_battle',
      'troop' => 'Boundary Troop',
      'defeatPolicy' => 'continue',
    ]]))->toThrow(InvalidArgumentException::class, 'irreversible command "start_battle"');
});

it('does not let a second skip cancel an active authored finalizer', function () {
  putSceneAudioConfig([]);
  [$scene, $interpreter] = makeEventRuntime();
  $scene->installPlayer(new EventTestPlayer(new Vector2(1, 1)));
  $scene->installCinematicRuntime();
  $scene->autoResumeTransfers = false;
  $cinematic = CinematicDefinition::fromArrays([
    'id' => 'single-finalizer',
    'name' => 'Single Finalizer',
    'skip' => ['policy' => 'authored'],
    'finalizer' => [
      ['type' => 'transfer', 'map' => 'map-b', 'x' => 4, 'y' => 5],
      ['type' => 'set_switch', 'name' => 'single_finalizer_done', 'value' => true],
    ],
  ], [['type' => 'wait', 'seconds' => 10]]);
  $session = $scene->cinematicController?->start($cinematic);

  expect($scene->skipCinematic())->toBeTrue()
    ->and($session?->isFinalizing)->toBeTrue()
    ->and($session?->status)->toBe(EventExecutionStatus::SUSPENDED)
    ->and($scene->skipCinematic())->toBeFalse()
    ->and($session?->isFinalizing)->toBeTrue()
    ->and($session?->status)->toBe(EventExecutionStatus::SUSPENDED)
    ->and($scene->gameState->getSwitch('single_finalizer_done'))->toBeFalse();

  $interpreter->resumeAfterTransfer();
  $interpreter->update(0.0);

  expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->gameState->getSwitch('single_finalizer_done'))->toBeTrue()
    ->and($scene->transferCount)->toBe(1);
  ConfigStore::remove(ProjectConfig::class);
});

it('uses cinematic transition coverage without the legacy blocking transfer transition', function (bool $reducedMotion) {
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => $reducedMotion]]);
  ConfigStore::put(PlaySettings::class, new SceneAudioConfigStub([
    'screen' => ['width' => 20, 'height' => 10],
  ]));

  try {
    [$scene] = makeEventRuntime();
    $scene->installPlayer(new EventTestPlayer(new Vector2(1, 1)));
    $scene->installCinematicRuntime();
    $cinematic = CinematicDefinition::fromArrays([
      'id' => 'covered-transfer',
      'name' => 'Covered Transfer',
    ], [
      ['type' => 'transition', 'style' => 'fade', 'direction' => 'out', 'seconds' => 0.1],
      ['type' => 'transfer', 'map' => 'map-b', 'x' => 2, 'y' => 3],
      ['type' => 'transition', 'style' => 'fade', 'direction' => 'in', 'seconds' => 0.1],
    ]);
    $session = $scene->cinematicController?->start($cinematic);

    for ($tick = 0; $tick < 10 && $scene->hasUnstableEventSession(); $tick++) {
      $scene->eventInterpreter?->update(0.1);
    }

    expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($scene->configuredTransferTransitions)->toBe([false])
      ->and($scene->cinematicCoverAtTransfer)->toBe([true])
      ->and($scene->cinematicPresentation?->hasTransitionCover())->toBeFalse();
  } finally {
    ConfigStore::remove(ProjectConfig::class);
    ConfigStore::remove(PlaySettings::class);
  }
})->with([
  'normal motion' => false,
  'reduced motion' => true,
]);

it('retains configured transfer transitions for ordinary event scripts', function () {
  putSceneAudioConfig([]);
  [$scene, $interpreter] = makeEventRuntime();
  $scene->installPlayer(new EventTestPlayer(new Vector2(1, 1)));
  $session = $interpreter->run([['type' => 'transfer', 'map' => 'map-b', 'x' => 2, 'y' => 3]], 'ordinary-transfer');
  $interpreter->update(0.0);

  expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->configuredTransferTransitions)->toBe([true]);
  ConfigStore::remove(ProjectConfig::class);
});

it('restores camera input and staged cast after controlled cinematic failure', function () {
  [$scene] = makeEventRuntime();
  $scene->installPlayer(new EventTestPlayer(new Vector2(1, 1)));
  $scene->installCinematicRuntime();
  $cinematic = CinematicDefinition::fromArrays([
    'id' => 'controlled-failure',
    'name' => 'Controlled Failure',
    'cast' => [['kind' => 'staged_actor', 'id' => 'visible-runner', 'sprite' => '@', 'x' => 2, 'y' => 2]],
  ], [
    ['type' => 'camera', 'operation' => 'detach'],
    ['type' => 'camera', 'operation' => 'focus', 'target' => ['kind' => 'staged_actor', 'id' => 'missing-runner']],
    ['type' => 'record_event', 'name' => 'must_not_complete'],
  ]);
  $session = $scene->cinematicController?->start($cinematic);

  expect($session?->status)->toBe(EventExecutionStatus::FAILED)
    ->and($session?->failureMessage)->toContain('Cinematic "controlled-failure" failed')
    ->and($session?->failureMessage)->toContain('lane "root"')
    ->and($session?->failureMessage)->toContain('command path')
    ->and($session?->failureMessage)->toContain('missing-runner')
    ->and($session?->failureMessage)->toContain('was not found')
    ->and($scene->gameState->hasStoryEvent('must_not_complete'))->toBeFalse()
    ->and($scene->gameState->hasStoryEvent('cinematic:controlled-failure:completed'))->toBeFalse()
    ->and($scene->cinematicStage?->all())->toBe([])
    ->and($scene->camera->followsPlayer)->toBeTrue()
    ->and($scene->cinematicController?->active())->toBeNull()
    ->and($scene->hasUnstableEventSession())->toBeFalse();
});

it('composes common events inside a cinematic lane and propagates nested failure context', function () {
  $root = sys_get_temp_dir() . '/cinematic-common-event-' . uniqid();
  mkdir($root . '/assets/Events', 0o777, true);
  file_put_contents($root . '/assets/Events/formation-ready.php', <<<'PHP'
<?php
return [
  ['type' => 'record_event', 'name' => 'common_event_entered'],
  ['type' => 'wait', 'seconds' => 0.1],
  ['type' => 'record_event', 'name' => 'common_event_completed'],
];
PHP);
  file_put_contents($root . '/assets/Events/broken-formation.php', <<<'PHP'
<?php
return [['type' => 'unknown_common_event_command']];
PHP);
  $previousDirectory = getcwd();
  chdir($root);

  try {
    [$scene, $interpreter] = makeEventRuntime();
    $completed = $interpreter->run([
      ['type' => 'common_event', 'id' => 'formation-ready'],
      ['type' => 'record_event', 'name' => 'cinematic_parent_completed'],
    ], 'common-event-cinematic');
    $interpreter->update(0.1);

    expect($completed?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($scene->gameState->storyEvents)->toBe([
        'common_event_entered',
        'common_event_completed',
        'cinematic_parent_completed',
      ]);

    $failed = $interpreter->run([
      ['type' => 'common_event', 'id' => 'broken-formation'],
      ['type' => 'record_event', 'name' => 'must_not_follow_common_failure'],
    ], 'broken-common-event');

    expect($failed?->status)->toBe(EventExecutionStatus::FAILED)
      ->and($failed?->failureMessage)->toContain('common_event:broken-formation[1]')
      ->and($failed?->failureMessage)->toContain('unknown_common_event_command')
      ->and($scene->gameState->hasStoryEvent('must_not_follow_common_failure'))->toBeFalse();
  } finally {
    chdir($previousDirectory);
  }
});

it('fails closed when a cinematic Common Event contains malformed command entries', function () {
  $root = sys_get_temp_dir() . '/cinematic-malformed-common-event-' . uniqid();
  mkdir($root . '/assets/Events', 0o777, true);
  file_put_contents($root . '/assets/Events/malformed.php', <<<'PHP'
<?php
return [
  ['type' => 'record_event', 'name' => 'must_not_be_recorded'],
  'silently dropped before this correction',
];
PHP);
  $previousDirectory = getcwd();
  chdir($root);

  try {
    [$scene] = makeEventRuntime();
    $scene->installPlayer(new EventTestPlayer(new Vector2(1, 1)));
    $scene->installCinematicRuntime();
    $definition = CinematicDefinition::fromArrays([
      'id' => 'malformed-common-event',
      'name' => 'Malformed Common Event',
    ], [['type' => 'common_event', 'id' => 'malformed']]);
    $session = $scene->cinematicController?->start($definition);

    expect($session?->status)->toBe(EventExecutionStatus::FAILED)
      ->and($session?->failureMessage)->toContain('Cinematic "malformed-common-event"')
      ->and($session?->failureMessage)->toContain('lane "root"')
      ->and($session?->failureMessage)->toContain('common_event:malformed')
      ->and($session?->failureMessage)->toContain('script[2]')
      ->and($scene->gameState->hasStoryEvent('must_not_be_recorded'))->toBeFalse();
  } finally {
    chdir($previousDirectory);
  }
});

it('pans, tracks moving staged subjects, shakes within bounds and resets the camera', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $scene->installPlayer(new EventTestPlayer(new Vector2(1, 1)));
  $scene->installCinematicRuntime();

  $pan = $interpreter->run([
    ['type' => 'camera', 'operation' => 'detach'],
    ['type' => 'camera', 'operation' => 'pan', 'target' => ['kind' => 'position', 'x' => 30, 'y' => 20], 'seconds' => 1.0],
  ], 'camera-pan');
  $interpreter->update(0.5);

  expect($pan?->status)->toBe(EventExecutionStatus::YIELDED)
    ->and([$scene->camera->position->x, $scene->camera->position->y])->toBe([11.0, 8.0]);

  $interpreter->update(0.5);
  expect($pan?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and([$scene->camera->position->x, $scene->camera->position->y])->toBe([21.0, 16.0]);

  $scene->cinematicStage?->add(['id' => 'tracked', 'sprite' => '@', 'x' => 30, 'y' => 20]);
  $track = $interpreter->run([[
    'type' => 'parallel',
    'lanes' => [
      ['id' => 'move', 'commands' => [[
        'type' => 'move_route',
        'subject' => 'staged_actor',
        'actorId' => 'tracked',
        'secondsPerStep' => 0.1,
        'steps' => [['direction' => 'right', 'count' => 2]],
      ]]],
      ['id' => 'track', 'commands' => [[
        'type' => 'camera',
        'operation' => 'track',
        'target' => ['kind' => 'staged_actor', 'id' => 'tracked'],
        'seconds' => 0.2,
      ]]],
    ],
  ]], 'camera-track');
  $interpreter->update(0.1);
  $interpreter->update(0.1);
  $interpreter->update(0.1);

  expect($track?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->cinematicStage?->require('tracked')->position->x)->toBe(32.0)
    ->and([$scene->camera->position->x, $scene->camera->position->y])->toBe([23.0, 16.0]);

  $base = clone $scene->camera->position;
  $shake = $interpreter->run([
    ['type' => 'camera', 'operation' => 'shake', 'seconds' => 0.2, 'magnitude' => 2],
    ['type' => 'camera', 'operation' => 'attach'],
  ], 'camera-shake');
  $interpreter->update(0.05);
  expect(abs($scene->camera->position->x - $base->x) + abs($scene->camera->position->y - $base->y))->toBeLessThanOrEqual(2);
  $interpreter->update(0.15);

  expect($shake?->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->camera->followsPlayer)->toBeTrue();
});

it('applies cinematic motion final states immediately when reduced motion is enabled', function () {
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => true]]);

  try {
    [$scene, $interpreter] = makeEventRuntime();
    $session = $interpreter->run([
      ['type' => 'camera', 'operation' => 'pan', 'target' => ['kind' => 'position', 'x' => 30, 'y' => 20], 'seconds' => 5.0],
      ['type' => 'record_event', 'name' => 'reduced_motion_camera_complete'],
    ], 'reduced-camera');

    expect($session?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and([$scene->camera->position->x, $scene->camera->position->y])->toBe([21.0, 16.0])
      ->and($scene->gameState->hasStoryEvent('reduced_motion_camera_complete'))->toBeTrue();

    $scene->installPlayer(new EventTestPlayer(new Vector2(1, 1)));
    $scene->installCinematicRuntime();
    $scene->cinematicStage?->add(['id' => 'reduced-runner', 'sprite' => '@', 'x' => 2, 'y' => 2]);
    $movement = $interpreter->run([[
      'type' => 'move_route',
      'subject' => 'staged_actor',
      'actorId' => 'reduced-runner',
      'secondsPerStep' => 5.0,
      'steps' => [['direction' => 'right', 'count' => 3]],
    ]], 'reduced-movement');
    $interpreter->update(0.0);

    expect($movement?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($scene->cinematicStage?->require('reduced-runner')->position->x)->toBe(5.0);

    $transition = $interpreter->run([[
      'type' => 'transition',
      'style' => 'fade',
      'direction' => 'in',
      'seconds' => 5.0,
    ]], 'reduced-transition');

    expect($transition?->status)->toBe(EventExecutionStatus::COMPLETED)
      ->and($scene->cinematicPresentation?->hasTransitionCover())->toBeFalse();
  } finally {
    ConfigStore::remove(ProjectConfig::class);
  }
});

it('uses safe deterministic staged-actor visibility and collision defaults', function () {
  [$scene] = makeEventRuntime();
  $scene->installPlayer(new EventTestPlayer(new Vector2(0, 0)));
  $scene->installCinematicRuntime();
  $nonColliding = $scene->cinematicStage?->add(['id' => 'ghost', 'sprite' => '@', 'x' => 2, 'y' => 2]);
  $colliding = $scene->cinematicStage?->add(['id' => 'solid', 'sprite' => '@', 'x' => 3, 'y' => 3, 'collision' => true]);

  expect($nonColliding?->hasCollision)->toBeFalse()
    ->and($scene->cinematicStage?->actorAt(2, 2))->toBeNull()
    ->and($scene->cinematicStage?->actorAt(3, 3))->toBe($colliding);

  $scene->cinematicStage?->hide('solid');
  expect($scene->cinematicStage?->actorAt(3, 3))->toBeNull()
    ->and($scene->cinematicStage?->require('solid')->isVisible)->toBeFalse();

  $scene->cinematicStage?->show('solid');
  expect($scene->cinematicStage?->actorAt(3, 3))->toBe($colliding)
    ->and($scene->cinematicStage?->require('solid')->isVisible)->toBeTrue();
});

it('enforces and diagnoses the complete staged-actor collision policy', function () {
  [$scene, $interpreter] = makeEventRuntime();
  $scene->installPlayer(new EventTestPlayer(new Vector2(2, 1)));
  $npcs = new NpcManager($scene);
  $npcs->configure([[
    'id' => 'visible-guide',
    'name' => 'Visible Guide',
    'sprite' => '@',
    'x' => 1,
    'y' => 2,
  ]]);
  $scene->installNpcManager($npcs);
  $scene->installCinematicRuntime();
  $scene->mapManager->blocked['1:0'] = true;
  $scene->cinematicStage?->add(['id' => 'mover', 'sprite' => '@', 'x' => 1, 'y' => 1, 'collision' => true]);
  $scene->cinematicStage?->add(['id' => 'other', 'sprite' => '@', 'x' => 0, 'y' => 1, 'collision' => true]);

  expect($scene->cinematicStage?->move('mover', new Vector2(1, 0)))->toBeFalse()
    ->and($scene->cinematicStage?->lastMoveFailure)->toContain('Staged actor "mover"')
    ->and($scene->cinematicStage?->lastMoveFailure)->toContain('(2, 1)')
    ->and($scene->cinematicStage?->lastMoveFailure)->toContain('the player')
    ->and($scene->cinematicStage?->move('mover', new Vector2(0, 1)))->toBeFalse()
    ->and($scene->cinematicStage?->lastMoveFailure)->toContain('visible NPC "visible-guide"')
    ->and($scene->cinematicStage?->move('mover', new Vector2(-1, 0)))->toBeFalse()
    ->and($scene->cinematicStage?->lastMoveFailure)->toContain('staged actor "other"');

  $blockedRoute = $interpreter->run([[
    'type' => 'move_route',
    'subject' => 'staged_actor',
    'actorId' => 'mover',
    'steps' => [['direction' => 'up', 'count' => 1]],
  ]], 'staged-collision-diagnostic');
  $interpreter->update(1.0);

  expect($blockedRoute?->status)->toBe(EventExecutionStatus::FAILED)
    ->and($blockedRoute?->failureMessage)->toContain('Staged actor "mover" cannot move to (1, 0)')
    ->and($blockedRoute?->failureMessage)->toContain('map collision "solid"');

  $ghost = $scene->cinematicStage?->add(['id' => 'ghost-route', 'sprite' => '@', 'x' => 1, 'y' => 1, 'collision' => false]);
  expect($scene->cinematicStage?->move('ghost-route', new Vector2(1, 0)))->toBeTrue()
    ->and([$ghost?->position->x, $ghost?->position->y])->toBe([2.0, 1.0])
    ->and($scene->cinematicStage?->lastMoveFailure)->toBeNull();
});
