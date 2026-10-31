<?php

use Ichiloto\Engine\Field\Enumerations\StepOrigin;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\MapSourceReader;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PreparedMap;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\PassiveGame;

final class TransferTriggerSaveProbe extends SaveManager
{
    public array $snapshots = [];

    public function __construct() {}

    public function autoSave(GameScene $scene): SaveSlot
    {
        assert($scene instanceof TransferTriggerSceneProbe);
        $this->snapshots[] = $scene->captureObservation();
        $scene->recordPoint('autosave');
        return SaveSlot::empty(self::AUTO_SAVE_SLOT, getcwd() . '/unwritten-autosave.iedata');
    }
}

final class TransferTriggerSceneManagerProbe extends SceneManager
{
    public function __construct(public TransferTriggerSaveProbe $saves)
    {
        $this->game = new PassiveGame();
        $this->saveManager = $saves;
    }
}

final class TransferTriggerMapProbe extends MapManager
{
    public function __construct(Game $game, GameScene $scene)
    {
        parent::__construct($game, $scene);
    }

    public function prepareMap(string $filename): PreparedMap
    {
        $this->gameScene->recordPoint('prepare');
        return parent::prepareMap($filename);
    }

    public function applyPreparedMap(PreparedMap $prepared, Player $player): void
    {
        $this->gameScene->recordPoint('before-commit');
        parent::applyPreparedMap($prepared, $player);
        $this->gameScene->recordPoint('committed');
    }
}

final class TransferTriggerPlayerProbe extends Player
{
    public array $blockedMessages = [];

    public function render(): void {}
    public function erase(): void {}
    public function erasePlayer(Camera $camera, ?array $sprite = null): void {}
    public function renderEventCues(): void {}
    protected function renderLocationHUDWindow(): void {}

    protected function announceBlockedEvent(string $message): void
    {
        $this->blockedMessages[] = $message;
    }

    public function findTrigger(string $marker): EventTrigger
    {
        foreach ($this->events as $trigger) {
            if ($trigger->marker === $marker) {
                return $trigger;
            }
        }
        throw new RuntimeException('Synthetic event marker is unavailable: ' . $marker);
    }
}

final class TransferTriggerPresentationProbe implements EventPresentationInterface
{
    public function beginText(string $text, string $name = ''): void {}
    public function beginChoice(string $prompt, array $options, string $title = ''): void {}
    public function update(): void {}
    public function render(): void {}
    public function isComplete(): bool { return true; }
    public function choiceResult(): ?int { return null; }
    public function reset(): void {}
}

final class TransferTriggerSceneProbe extends GameScene
{
    public array $journal = [];
    public array $failures = [];
    public int $transferRequests = 0;
    public int $committedCallbacks = 0;
    public TransferTriggerSaveProbe $saves;

    public function __construct()
    {
        $this->saves = new TransferTriggerSaveProbe();
        $this->sceneManager = new TransferTriggerSceneManagerProbe($this->saves);
        $this->sceneManager->currentScene = $this;
        $this->gameState = new GameState();
        $this->party = new Party();
        $this->camera = new Camera($this, 8, 4, worldSpace: []);
        $this->uiManager = new class($this) extends UIManager {
            public function __construct(private TransferTriggerSceneProbe $scene) {}
            public function render(): void { $this->scene->recordPoint('rendered'); }
        };
        $this->locationHUDWindow = new class extends LocationHUDWindow {
            public function __construct() {}
            public function updateDetails(Vector2 $position, MovementHeading $heading): void {}
        };
        $this->mapManager = new TransferTriggerMapProbe($this->getGame(), $this);
        $this->player = new TransferTriggerPlayerProbe($this, 'Synthetic player', new Vector2(0, 1),
            new Rect(0, 0, 1, 1), ['@']);
        $this->eventInterpreter = new EventInterpreter($this, new TransferTriggerPresentationProbe());
        $this->gameState->onChange = function (string $kind, string $name): void {
            $this->recordPoint('write:' . $kind . ':' . $name);
            $this->handleWorldStateChange($kind, $name);
        };
    }

    public function captureObservation(): array
    {
        return [
            'map' => $this->currentMapId,
            'location' => $this->party->location?->name,
            'position' => [$this->player->position->x, $this->player->position->y],
            'tiles' => $this->mapManager->tileMap,
            'state' => $this->gameState->toArray(),
        ];
    }

    public function recordPoint(string $phase): void
    {
        $this->journal[] = ['phase' => $phase, ...$this->captureObservation()];
    }

    public function loadInitialMap(): void
    {
        $this->currentMapId = 'test/source';
        $this->mapManager->loadMap($this->currentMapId, $this->player);
        $this->journal = [];
    }

    public function startInitialEvents(): void
    {
        $this->startConfiguredFieldEvents();
    }

    public function refreshFieldMusic(bool $force = false, bool $keepSilence = false): void {}

    public function transferPlayer(Location $location, bool $useConfiguredTransition = true, ?Closure $onCommitted = null): bool
    {
        $this->transferRequests++;
        $callback = $onCommitted === null ? null : function () use ($onCommitted): void {
            $this->committedCallbacks++;
            $this->recordPoint('callback');
            $onCommitted();
        };
        return parent::transferPlayer($location, $useConfiguredTransition, $callback);
    }

    protected function finalizePlayerTransfer(): void
    {
        $this->recordPoint('finalize');
        parent::finalizePlayerTransfer();
    }

    public function onEventSessionStarted(EventExecutionSession $session): void
    {
        $this->recordPoint('event-start');
        parent::onEventSessionStarted($session);
    }

    protected function reportMapLoadFailure(string $mapId, Throwable $error): void
    {
        $this->failures[] = ['map' => $mapId, 'message' => $error->getMessage()];
    }
}

function writeTransferTriggerMap(string $root, string $id, array $events, string $eventGrid, string $tile = 's',
    ?array $occupancy = null): void
{
    $paths = MapSourceReader::resolvePaths($root . '/assets/Maps', $id);
    mkdir(dirname($paths['data']), 0700, true);
    file_put_contents($paths['map'], MapGridSource::buildSource(implode("\n", array_fill(0, 3, str_repeat($tile, 5))), 'MAP'));
    file_put_contents($paths['event'], MapGridSource::buildSource($eventGrid, 'EVENT'));
    file_put_contents($paths['data'], '<?php return ' . var_export([
        'name' => $id === 'test/source' ? 'Source' : 'Destination',
        'region' => 'Synthetic',
        'occupancy' => $occupancy ?? array_fill(0, 3, array_fill(0, 5, CollisionType::NONE)),
        'events' => $events,
    ], true) . ';');
}

function createTransferTriggerScene(array $data = [], array $conditions = [], ?string $whenBlocked = null,
    bool $destinationAuto = false, bool $solidEntry = false): TransferTriggerSceneProbe
{
    $root = createTestDirectory('ichiloto-transfer-trigger-');
    $sourceData = [
        'destinationMap' => 'test/destination',
        'spawnPoint' => ['x' => 1, 'y' => 1],
        'spawnSprite' => ['@'],
        ...$data,
    ];
    $occupancy = array_fill(0, 3, array_fill(0, 5, CollisionType::NONE));
    if ($solidEntry) {
        $occupancy[1][1] = CollisionType::SOLID;
    }
    writeTransferTriggerMap($root, 'test/source', ['T' => [
        'class' => TransferPlayerTrigger::class,
        'data' => $sourceData,
        'conditions' => $conditions,
        'whenBlocked' => $whenBlocked,
        'sets' => [
            ['type' => 'event', 'name' => 'boundary_crossed'],
            ['type' => 'variable', 'name' => 'crossings', 'op' => 'add', 'value' => 1],
        ],
    ]], "     \n T   \n     ", occupancy: $occupancy);
    $destinationEvents = ['T' => [
        'class' => TransferPlayerTrigger::class,
        'data' => ['destinationMap' => 'test/source', 'spawnPoint' => ['x' => 0, 'y' => 1],
            'spawnSprite' => ['@'], 'reusable' => true],
    ]];
    if ($destinationAuto) {
        $destinationEvents['A'] = [
            'class' => ScriptEventTrigger::class,
            'conditions' => [['type' => 'event', 'name' => 'boundary_crossed']],
            'data' => ['mode' => 'auto', 'reusable' => false, 'script' => [
                ['type' => 'set_switch', 'name' => 'arrival_observed', 'value' => true],
            ]],
        ];
    }
    writeTransferTriggerMap($root, 'test/destination', $destinationEvents,
        $destinationAuto ? "     \n A T \n     " : "     \n   T \n     ", 'd');
    chdir($root);
    Debug::configure(['log_directory' => $root . '/logs']);
    $scene = new TransferTriggerSceneProbe();
    $scene->loadInitialMap();
    return $scene;
}

beforeEach(function () {
    $this->transferPreviousCwd = getcwd();
    $this->transferPreviousConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
    $this->transferPreviousEventManager = new ReflectionProperty(EventManager::class, 'instance')->getValue();
    new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
    $this->transferPreviousDebug = new ReflectionClass(Debug::class)->getStaticProperties();
    putSceneAudioConfig(['audio' => ['music' => false, 'sfx' => false, 'voice' => false],
        'save' => ['autosave' => true], 'ui' => ['hud' => ['location' => false]]]);
});

afterEach(function () {
    chdir($this->transferPreviousCwd);
    if ($this->transferPreviousConfig === null) {
        ConfigStore::remove(ProjectConfig::class);
    } else {
        ConfigStore::put(ProjectConfig::class, $this->transferPreviousConfig);
    }
    new ReflectionProperty(EventManager::class, 'instance')->setValue(null, $this->transferPreviousEventManager);
    foreach ($this->transferPreviousDebug as $name => $value) {
        new ReflectionProperty(Debug::class, $name)->setValue(null, $value);
    }
});

it('completes transfer writes after real destination commit before automatic events and removes automatic transfer autosave', function () {
    $scene = createTransferTriggerScene(destinationAuto: true);
    $trigger = $scene->player->findTrigger('T');
    expect($scene->player->tryMove(Vector2::right(), $scene->camera, StepOrigin::PLAYER_INPUT))->toBeTrue();
    $phases = array_column($scene->journal, 'phase');
    expect($phases)->toBe(['prepare', 'before-commit', 'committed', 'rendered', 'callback',
        'write:event:boundary_crossed', 'write:variable:crossings', 'finalize', 'event-start',
        'write:switch:arrival_observed'])
        ->and($scene->committedCallbacks)->toBe(1)
        ->and($trigger->isReusable)->toBeTrue()
        ->and($trigger->isComplete)->toBeFalse();
    foreach ($scene->journal as $observation) {
        if (! str_starts_with($observation['phase'], 'write:')) { continue; }
        expect($observation['map'])->toBe('test/destination')
            ->and($observation['location'])->toBe('Destination')
            ->and($observation['tiles'][0])->toBe(array_fill(0, 5, 'd'));
    }
    expect($scene->saves->snapshots)->toBe([]);
    $scene->autoSave(); // Developer chooses the checkpoint after destination events.
    $save = $scene->saves->snapshots[0];
    expect($scene->saves->snapshots)->toHaveCount(1)
        ->and($save['map'])->toBe('test/destination')
        ->and($save['state']['storyEvents'])->toBe(['boundary_crossed'])
        ->and($save['state']['variables']['crossings'])->toBe(1)
        ->and($save['state']['switches']['arrival_observed'])->toBeTrue()
        ->and($scene->journal[2]['state']['storyEvents'])->toBe([])
        ->and($scene->journal[2]['state']['variables'])->toBe([]);
});

it('persists one-shot completion under source identity and restores it on return', function () {
    $scene = createTransferTriggerScene(['reusable' => false]);
    $original = $scene->player->findTrigger('T');
    $scene->player->tryMove(Vector2::right(), $scene->camera, StepOrigin::PLAYER_INPUT);
    expect($original->isComplete)->toBeTrue()
        ->and($scene->gameState->isEventComplete('test/source', 'T'))->toBeTrue()
        ->and($scene->gameState->isEventComplete('test/destination', 'T'))->toBeFalse()
        ->and($scene->player->findTrigger('T')->isComplete)->toBeFalse()
        ->and($scene->gameState->toArray()['completedEvents'])->toBe(['test/source:T' => true]);
    expect($scene->player->tryMove(Vector2::right(), $scene->camera, StepOrigin::PLAYER_INPUT))->toBeTrue()
        ->and($scene->player->tryMove(Vector2::right(), $scene->camera, StepOrigin::PLAYER_INPUT))->toBeTrue()
        ->and($scene->currentMapId)->toBe('test/source');
    $restored = $scene->player->findTrigger('T');
    expect($restored)->not->toBe($original)->and($restored->isComplete)->toBeTrue();
    $requests = $scene->transferRequests;
    $saves = count($scene->saves->snapshots);
    expect($scene->player->tryMove(Vector2::right(), $scene->camera, StepOrigin::PLAYER_INPUT))->toBeTrue()
        ->and($scene->currentMapId)->toBe('test/source')
        ->and($scene->transferRequests)->toBe($requests)
        ->and($scene->committedCallbacks)->toBe(2)
        ->and($scene->gameState->getVariable('crossings'))->toBe(1)
        ->and($scene->saves->snapshots)->toHaveCount($saves);
});

it('applies variable writes once per reusable pass while story writes stay idempotent', function (array $data) {
    $scene = createTransferTriggerScene($data);
    for ($pass = 1; $pass <= 2; $pass++) {
        $trigger = $scene->player->findTrigger('T');
        expect($scene->player->tryMove(Vector2::right(), $scene->camera, StepOrigin::PLAYER_INPUT))->toBeTrue()
            ->and($scene->currentMapId)->toBe('test/destination')
            ->and($scene->gameState->getVariable('crossings'))->toBe($pass)
            ->and($scene->committedCallbacks)->toBe(2 * $pass - 1)
            ->and($trigger->isComplete)->toBeFalse();
        expect($scene->player->tryMove(Vector2::right(), $scene->camera, StepOrigin::PLAYER_INPUT))->toBeTrue()
            ->and($scene->player->tryMove(Vector2::right(), $scene->camera, StepOrigin::PLAYER_INPUT))->toBeTrue()
            ->and($scene->currentMapId)->toBe('test/source');
    }
    expect($scene->gameState->storyEvents)->toBe(['boundary_crossed'])
        ->and($scene->gameState->isEventComplete('test/source', 'T'))->toBeFalse()
        ->and(array_count_values(array_column($scene->journal, 'phase'))['write:variable:crossings'])->toBe(2)
        ->and(array_count_values(array_column($scene->journal, 'phase'))['write:event:boundary_crossed'])->toBe(1)
        ->and($scene->saves->snapshots)->toBe([]);
})->with(['default reusable' => [[]], 'explicit reusable' => [['reusable' => true]]]);

it('does not write or complete when movement cannot activate a transfer', function (string $blocker, bool $moves) {
    $conditions = $blocker === 'collision' ? [] : [['type' => 'switch', 'name' => 'authorized', 'value' => true]];
    $scene = createTransferTriggerScene(['reusable' => false], $conditions,
        $blocker === 'gate' ? 'Synthetic route is locked.' : null, solidEntry: $blocker === 'collision');
    $trigger = $scene->player->findTrigger('T');
    $before = $scene->gameState->toArray();
    expect($scene->player->tryMove(Vector2::right(), $scene->camera, StepOrigin::PLAYER_INPUT))->toBe($moves)
        ->and($scene->transferRequests)->toBe(0)
        ->and($scene->committedCallbacks)->toBe(0)
        ->and($scene->gameState->toArray())->toBe($before)
        ->and($trigger->isComplete)->toBeFalse()
        ->and($scene->saves->snapshots)->toBe([])
        ->and($scene->currentMapId)->toBe('test/source')
        ->and($scene->player->position->x)->toBe($moves ? 1.0 : 0.0);
    expect($scene->player->blockedMessages)->toBe($blocker === 'gate' ? ['Synthetic route is locked.'] : []);
})->with(['physical collision' => ['collision', false], 'authored gate' => ['gate', false],
    'unavailable event without movement gate' => ['unavailable', true]]);

it('refuses invalid destination sources without callback writes completion or autosave', function (string $failure) {
    $map = $failure === 'missing' ? 'test/missing' : ($failure === 'unsafe' ? '../outside' : 'test/destination');
    $scene = createTransferTriggerScene(['destinationMap' => $map, 'reusable' => false]);
    if ($failure === 'malformed') {
        $paths = MapSourceReader::resolvePaths(getcwd() . '/assets/Maps', 'test/destination');
        file_put_contents($paths['event'], MapGridSource::buildSource(" \n \n ", 'EVENT'));
    }
    $trigger = $scene->player->findTrigger('T');
    $before = $scene->gameState->toArray();
    $tiles = $scene->mapManager->tileMap;
    $scene->player->tryMove(Vector2::right(), $scene->camera, StepOrigin::PLAYER_INPUT);
    expect($scene->transferRequests)->toBe(1)
        ->and($scene->committedCallbacks)->toBe(0)
        ->and($scene->failures)->toHaveCount(1)
        ->and($scene->failures[0]['map'])->toBe($map)
        ->and($scene->currentMapId)->toBe('test/source')
        ->and($scene->party->location->name)->toBe('Source')
        ->and($scene->mapManager->tileMap)->toBe($tiles)
        ->and($scene->gameState->toArray())->toBe($before)
        ->and($trigger->isComplete)->toBeFalse()
        ->and($scene->saves->snapshots)->toBe([])
        ->and(array_column($scene->journal, 'phase'))->toBe(['prepare']);
})->with(['missing', 'malformed', 'unsafe']);

it('keeps transfer markers movement driven on initial field entry', function () {
    $scene = createTransferTriggerScene();
    $scene->player->position->x = 1;
    $before = $scene->gameState->toArray();
    $scene->startInitialEvents();
    expect($scene->currentMapId)->toBe('test/source')
        ->and($scene->transferRequests)->toBe(0)
        ->and($scene->committedCallbacks)->toBe(0)
        ->and($scene->gameState->toArray())->toBe($before)
        ->and($scene->saves->snapshots)->toBe([]);
});

it('retains successful two-argument callers without an implicit completion callback', function () {
    $scene = createTransferTriggerScene();
    expect($scene->transferPlayer(new Location('test/destination', new Vector2(2, 1), null), false))->toBeTrue()
        ->and($scene->currentMapId)->toBe('test/destination')
        ->and($scene->committedCallbacks)->toBe(0)
        ->and($scene->gameState->hasStoryEvent('boundary_crossed'))->toBeFalse()
        ->and($scene->gameState->getVariable('crossings'))->toBe(0)
        ->and($scene->saves->snapshots)->toBe([]);
});

it('invokes a direct commit callback once after render before finalization and never on refusal', function () {
    $scene = createTransferTriggerScene();
    $calls = 0;
    $callback = function () use ($scene, &$calls): void {
        $calls++;
        expect($scene->currentMapId)->toBe('test/destination')
            ->and($scene->party->location->name)->toBe('Destination')
            ->and($scene->mapManager->tileMap[0])->toBe(array_fill(0, 5, 'd'));
        $scene->gameState->addToVariable('direct_commits', 1);
    };
    expect($scene->transferPlayer(new Location('test/missing', new Vector2(1, 1), null), false, $callback))->toBeFalse()
        ->and($calls)->toBe(0)->and($scene->saves->snapshots)->toBe([]);
    $scene->journal = [];
    expect($scene->transferPlayer(new Location('test/destination', new Vector2(1, 1), null), false, $callback))->toBeTrue();
    $scene->updateEventSession(10);
    expect($calls)->toBe(1)
        ->and($scene->committedCallbacks)->toBe(1)
        ->and($scene->gameState->getVariable('direct_commits'))->toBe(1)
        ->and(array_column($scene->journal, 'phase'))->toBe(['prepare', 'before-commit', 'committed',
            'rendered', 'callback', 'write:variable:direct_commits', 'finalize']);
});
