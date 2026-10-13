<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\ProjectState\ProjectStateData;
use Ichiloto\Engine\Core\ProjectState\ProjectStateException;
use Ichiloto\Engine\Core\ProjectState\ProjectStateParticipantInterface;
use Ichiloto\Engine\Core\ProjectState\ProjectStateRegistry;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Exceptions\ActiveEventSaveException;
use Ichiloto\Engine\Exceptions\CorruptSaveException;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityPipeline;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

class SyntheticProjectStateParticipant implements ProjectStateParticipantInterface
{
    public array $migrations = [];
    public bool $failMigration = false;
    public ?Closure $validation = null;

    public function __construct(public readonly string $namespace = 'fixture/progress', public readonly int $version = 2) {}

    public function migrateState(array $data, int $fromVersion): array
    {
        $this->migrations[] = $fromVersion;
        if ($this->failMigration || $fromVersion !== 1 || $this->version !== 2) {
            throw new RuntimeException('Synthetic migration refused.');
        }
        return $data === [] ? [] : ['resourceId' => $data['oldId'], 'count' => $data['count']];
    }

    public function validateState(array $data): void
    {
        if ($data !== [] && (!in_array($data['resourceId'] ?? null, ['resource-a', 'resource-b'], true)
            || !is_int($data['count'] ?? null) || $data['count'] < 0)) {
            throw new RuntimeException('Invalid synthetic reference or count.');
        }
        ($this->validation ?? static function () {})($data);
    }
}

final class ReplacementProjectStateParticipant extends SyntheticProjectStateParticipant {}

final class ProjectStateTestGame extends Game
{
    public function __construct(private readonly ?string $fixtureRoot = null) {}
    public function __destruct() {}
    public function getDataRoot(): string { return $this->fixtureRoot ?? parent::getDataRoot(); }
}

/** Uses real configure transaction and snapshot, but no maps, renderer, audio or real settings. */
final class ProjectStateSceneProbe extends GameScene
{
    public bool $failConfiguration = false;
    public bool $unstable = false;
    public array $seenState = [];
    public int $configurationCount = 0;

    public function __construct(private Game $owner) {}
    public function getGame(): Game { return $this->owner; }
    public function hasUnstableEventSession(): bool { return $this->unstable; }

    protected function configureCampaign(GameConfig $config): void
    {
        $this->configurationCount++;
        $this->seenState = $this->owner->getProjectStateRegistry()->getState('fixture/progress');
        if ($this->failConfiguration) {
            $this->owner->getProjectStateRegistry()->replaceStates(['fixture/progress' => ['resourceId' => 'resource-b', 'count' => 90]]);
            throw new RuntimeException('Synthetic field setup failed.');
        }
        $this->config = $config;
        $this->party = $config->party;
        $this->gameState = GameState::fromArray($config->gameState);
        $this->player = new class($config) extends Player {
            public function __construct(GameConfig $config)
            {
                $this->position = clone $config->playerPosition;
                $this->heading = $config->playerHeading;
                $this->sprite = $config->playerSprite;
            }
            public function getShape(): Rect { return new Rect(0, 0, 1, 1); }
        };
    }
}

function createProjectStateConfig(array $projectState = []): GameConfig
{
    return new GameConfig('fixture-map', new Party(), new Vector2(1, 2), new Rect(0, 0, 1, 1),
        MovementHeading::SOUTH, projectState: $projectState);
}

function createProjectStateManifest(): SaveCompatibilityManifest
{
    return SaveCompatibilityManifest::fromArray('fixture/project', ['contentVersion' => 0, 'migrations' => [],
        'aliases' => [], 'tombstones' => []], 'synthetic project-state fixture');
}

function createProjectStateEnvelope(GameConfig $config): array
{
    return new SaveCompatibilityPipeline(createProjectStateManifest())->createEnvelope(
        SaveSlot::empty(1, '/unused-synthetic-save'), $config);
}

it('restores an older save without a section as no progress and never calls a grant or migration', function () {
    $participant = new SyntheticProjectStateParticipant();
    $game = new ProjectStateTestGame();
    $game->registerProjectStateParticipant($participant);
    $game->getProjectStateRegistry()->replaceStates(['fixture/progress' => ['resourceId' => 'resource-a', 'count' => 7]]);
    $data = createProjectStateConfig()->__serialize();
    unset($data['projectState']);
    $older = new ReflectionClass(GameConfig::class)->newInstanceWithoutConstructor();
    $older->__unserialize($data);
    $pipeline = new SaveCompatibilityPipeline(createProjectStateManifest(), projectStateRegistry: $game->getProjectStateRegistry());
    $loaded = $pipeline->load(serialize(['slot' => SaveSlot::empty(1, '/unused'), 'config' => $older]), '/unused');
    expect($game->getProjectStateRegistry()->getState('fixture/progress')['count'])->toBe(7);
    $scene = new ProjectStateSceneProbe($game);
    $scene->configure($loaded->config);
    expect($scene->seenState)->toBe([])
        ->and($game->getProjectStateRegistry()->getState('fixture/progress'))->toBe([])
        ->and($participant->migrations)->toBe([]);
});

it('migrates with a replaceable provider under stable namespace identity and not its class or artwork', function () {
    $snapshot = ['fixture/progress' => ['version' => 1, 'data' => ['oldId' => 'resource-a', 'count' => 4]]];
    $replacement = new ProjectStateRegistry();
    $provider = new ReplacementProjectStateParticipant();
    $replacement->registerParticipant($provider);
    $replacement->restoreSnapshot($snapshot);
    expect($replacement->getState('fixture/progress'))->toBe(['resourceId' => 'resource-a', 'count' => 4])
        ->and($replacement->captureSnapshot()['fixture/progress']['version'])->toBe(2)
        ->and($provider->migrations)->toBe([1]);
});

it('keeps namespaces and Game instances separate and commits multi-namespace updates together', function () {
    $game = new ProjectStateTestGame();
    $game->registerProjectStateParticipant(new SyntheticProjectStateParticipant());
    $game->registerProjectStateParticipant(new SyntheticProjectStateParticipant('fixture/other'));
    $registry = $game->getProjectStateRegistry();
    $registry->replaceStates(['fixture/progress' => ['resourceId' => 'resource-a', 'count' => 3],
        'fixture/other' => ['resourceId' => 'resource-b', 'count' => 8]]);
    $before = $registry->captureSnapshot();
    expect(fn() => $registry->replaceStates(['fixture/progress' => ['resourceId' => 'resource-b', 'count' => 6],
        'fixture/other' => ['resourceId' => 'missing', 'count' => 1]]))->toThrow(ProjectStateException::class);
    $secondGame = new ProjectStateTestGame();
    $secondGame->registerProjectStateParticipant(new SyntheticProjectStateParticipant());
    expect($registry->captureSnapshot())->toBe($before)
        ->and($registry->getState('fixture/other')['count'])->toBe(8)
        ->and($secondGame->getProjectStateRegistry()->getState('fixture/progress'))->toBe([]);
});

it('validates every participant before hydration and leaves all state unchanged on migration failure', function () {
    $registry = new ProjectStateRegistry();
    $first = new SyntheticProjectStateParticipant();
    $second = new SyntheticProjectStateParticipant('fixture/other');
    $registry->registerParticipant($first);
    $registry->registerParticipant($second);
    $registry->replaceStates(['fixture/progress' => ['resourceId' => 'resource-a', 'count' => 5],
        'fixture/other' => ['resourceId' => 'resource-b', 'count' => 9]]);
    $before = $registry->captureSnapshot();
    $second->failMigration = true;
    expect(fn() => $registry->restoreSnapshot([
        'fixture/progress' => ['version' => 1, 'data' => ['oldId' => 'resource-b', 'count' => 10]],
        'fixture/other' => ['version' => 1, 'data' => ['oldId' => 'resource-a', 'count' => 1]],
    ]))->toThrow(ProjectStateException::class, 'fixture/other');
    expect($registry->captureSnapshot())->toBe($before)->and($first->migrations)->toBe([1]);
});

it('refuses registry mutation and reads from migration or validation callbacks', function () {
    $registry = new ProjectStateRegistry();
    $provider = new SyntheticProjectStateParticipant();
    $registry->registerParticipant($provider);
    $provider->validation = fn() => $registry->replaceStates(['fixture/progress' => []]);
    expect(fn() => $registry->restoreSnapshot([]))->toThrow(ProjectStateException::class, 'must not mutate');
    $provider->validation = fn() => $registry->getState('fixture/progress');
    expect(fn() => $registry->restoreSnapshot([]))->toThrow(ProjectStateException::class, 'must not mutate');
    $provider->validation = null;
    expect($registry->getState('fixture/progress'))->toBe([]);
});

it('refuses namespace collisions invalid namespace versions late registration and future data', function () {
    $registry = new ProjectStateRegistry();
    expect(fn() => $registry->registerParticipant(new SyntheticProjectStateParticipant('bad')))->toThrow(ProjectStateException::class)
        ->and(fn() => $registry->registerParticipant(new SyntheticProjectStateParticipant(version: 0)))->toThrow(ProjectStateException::class);
    $registry->registerParticipant(new SyntheticProjectStateParticipant());
    expect(fn() => $registry->registerParticipant(new SyntheticProjectStateParticipant()))->toThrow(ProjectStateException::class)
        ->and(fn() => $registry->restoreSnapshot(['fixture/progress' => ['version' => 3, 'data' => []]]))->toThrow(ProjectStateException::class, 'newer');
    $registry->restoreSnapshot([]);
    expect(fn() => $registry->registerParticipant(new SyntheticProjectStateParticipant('fixture/late')))->toThrow(ProjectStateException::class, 'before');
});

it('detaches shared PHP references so saved and live state cannot change behind validation', function () {
    $leaf = 2;
    $payload = ['resourceId' => 'resource-a', 'count' => &$leaf];
    $game = new ProjectStateTestGame();
    $game->registerProjectStateParticipant(new SyntheticProjectStateParticipant());
    $game->getProjectStateRegistry()->replaceStates(['fixture/progress' => $payload]);
    $snapshot = $game->getProjectStateRegistry()->captureSnapshot();
    $leaf = -100;
    $read = $game->getProjectStateRegistry()->getState('fixture/progress');
    $read['count'] = 800;
    expect($snapshot['fixture/progress']['data']['count'])->toBe(2)
        ->and($game->getProjectStateRegistry()->getState('fixture/progress')['count'])->toBe(2);
});

it('preserves safely bounded unknown providers through configure snapshot and the same save envelope', function () {
    $game = new ProjectStateTestGame();
    $game->registerProjectStateParticipant(new SyntheticProjectStateParticipant());
    $unknown = ['version' => 90, 'data' => ['pending' => ['id' => 'stable-id'], 'empty' => [], 'ratio' => 0.5, 2 => null]];
    $scene = new ProjectStateSceneProbe($game);
    $scene->configure(createProjectStateConfig(['unavailable/module' => $unknown]));
    $registry = $game->getProjectStateRegistry();
    expect($registry->diagnostics)->toHaveCount(1)
        ->and($registry->diagnostics[0])->toContain('unavailable/module', 'retained unchanged');
    $pipeline = new SaveCompatibilityPipeline(createProjectStateManifest(), projectStateRegistry: $registry);
    $loaded = $pipeline->load(serialize(createProjectStateEnvelope($scene->createSnapshot())), '/unused');
    expect($loaded->config->projectState['unavailable/module'])->toBe($unknown)
        ->and(fn() => $registry->getState('unavailable/module'))->toThrow(ProjectStateException::class)
        ->and(fn() => $registry->replaceStates(['unavailable/module' => []]))->toThrow(ProjectStateException::class);
});

it('rejects malformed sections and non-plain or non-finite data', function (mixed $snapshot) {
    expect(fn() => ProjectStateData::copySnapshot($snapshot))->toThrow(ProjectStateException::class);
})->with([
    'null root' => [null],
    'non-namespaced key' => [['progress' => ['version' => 1, 'data' => []]]],
    'numeric namespace' => [[1 => ['version' => 1, 'data' => []]]],
    'string version' => [['fixture/progress' => ['version' => '1', 'data' => []]]],
    'zero version' => [['fixture/progress' => ['version' => 0, 'data' => []]]],
    'scalar data' => [['fixture/progress' => ['version' => 1, 'data' => false]]],
    'unexpected section field' => [['fixture/progress' => ['version' => 1, 'data' => [], 'class' => 'arbitrary']]],
    'nested object' => [['fixture/progress' => ['version' => 1, 'data' => [new stdClass()]]]],
    'nested closure' => [['fixture/progress' => ['version' => 1, 'data' => [static fn() => null]]]],
    'infinity' => [['fixture/progress' => ['version' => 1, 'data' => [INF]]]],
    'nan' => [['fixture/progress' => ['version' => 1, 'data' => [NAN]]]],
]);

it('rejects resources cycles excessive nesting values bytes and namespaces', function () {
    $resource = fopen('php://memory', 'r+');
    try {
        expect(fn() => ProjectStateData::copySnapshot(['fixture/progress' => ['version' => 1, 'data' => [$resource]]]))->toThrow(ProjectStateException::class);
    } finally { fclose($resource); }
    $cycle = [];
    $cycle['self'] = &$cycle;
    $deep = [];
    for ($i = 0; $i <= ProjectStateData::MAX_DEPTH; $i++) { $deep = [$deep]; }
    foreach ([$cycle, $deep, array_fill(0, ProjectStateData::MAX_VALUES + 1, null), [str_repeat('x', ProjectStateData::MAX_BYTES + 1)]] as $data) {
        expect(fn() => ProjectStateData::copySnapshot(['fixture/progress' => ['version' => 1, 'data' => $data]]))->toThrow(ProjectStateException::class);
    }
    $namespaces = [];
    for ($i = 0; $i <= ProjectStateData::MAX_NAMESPACES; $i++) { $namespaces['fixture/module-' . $i] = ['version' => 1, 'data' => []]; }
    expect(fn() => ProjectStateData::copySnapshot($namespaces))->toThrow(ProjectStateException::class);
    unset($cycle['self']);
});

it('rejects explicitly null decoded project state rather than treating it as an older save', function () {
    $data = createProjectStateConfig()->__serialize();
    $data['projectState'] = null;
    expect(fn() => createProjectStateConfig()->__unserialize($data))->toThrow(ProjectStateException::class);
});

it('rejects malformed project state during shared pipeline decoding with a controlled save error', function () {
    $serialized = serialize(createProjectStateEnvelope(createProjectStateConfig()));
    $malformed = str_replace('s:12:"projectState";a:0:{}', 's:12:"projectState";N;', $serialized, $replacements);
    expect($replacements)->toBe(1);
    $pipeline = new SaveCompatibilityPipeline(createProjectStateManifest());
    expect(fn() => $pipeline->load($malformed, '/synthetic-corrupt-save'))->toThrow(CorruptSaveException::class, '/synthetic-corrupt-save');
});

it('enforces aggregate size and serialized overhead limits rather than just each namespace', function () {
    $snapshot = [
        'fixture/first' => ['version' => 1, 'data' => [str_repeat('x', 600_000)]],
        'fixture/second' => ['version' => 1, 'data' => [str_repeat('x', 600_000)]],
    ];
    expect(fn() => ProjectStateData::copySnapshot($snapshot))->toThrow(ProjectStateException::class, 'size');
    $overhead = array_fill(0, 90_000, '');
    expect(fn() => ProjectStateData::copySnapshot(['fixture/progress' => ['version' => 1, 'data' => $overhead]]))->toThrow(ProjectStateException::class, 'serialized size');
});

it('accepts the documented nesting boundary and ordinary mixed plain values', function () {
    $data = [];
    for ($i = 0; $i < ProjectStateData::MAX_DEPTH; $i++) { $data = [$data]; }
    $snapshot = ['fixture/progress' => ['version' => 1, 'data' => $data],
        'fixture/values' => ['version' => 1, 'data' => ['text', 1, -1, 1.5, true, false, null, 'key' => ['nested' => []]]]];
    expect(ProjectStateData::copySnapshot($snapshot))->toBe($snapshot);
});

it('bounds and validates migration output before changing any registered state', function (string $failureMode) {
    $registry = new ProjectStateRegistry();
    $provider = new class($failureMode) extends SyntheticProjectStateParticipant {
        public function __construct(private string $failureMode) { parent::__construct(); }
        public function migrateState(array $data, int $fromVersion): array
        {
            return match ($this->failureMode) {
                'object' => [new stdClass()],
                'oversized' => [str_repeat('x', ProjectStateData::MAX_BYTES + 1)],
                default => ['resourceId' => 'unresolved', 'count' => 1],
            };
        }
    };
    $registry->registerParticipant($provider);
    $registry->replaceStates(['fixture/progress' => ['resourceId' => 'resource-a', 'count' => 6]]);
    $before = $registry->captureSnapshot();
    expect(fn() => $registry->restoreSnapshot(['fixture/progress' => ['version' => 1, 'data' => []]]))->toThrow(ProjectStateException::class);
    expect($registry->captureSnapshot())->toBe($before);
})->with(['object', 'oversized', 'unresolved']);

it('rejects nested campaign restoration without corrupting the previous campaign', function () {
    $registry = new ProjectStateRegistry();
    $registry->registerParticipant(new SyntheticProjectStateParticipant());
    $registry->replaceStates(['fixture/progress' => ['resourceId' => 'resource-a', 'count' => 3]]);
    expect(fn() => $registry->runWithSnapshot([], fn() => $registry->runWithSnapshot([], static function () {})))
        ->toThrow(ProjectStateException::class, 'Nested');
    expect($registry->getState('fixture/progress')['count'])->toBe(3);
});

it('prepares the shared pipeline without changing live state and refuses project reference migration errors', function () {
    $registry = new ProjectStateRegistry();
    $provider = new SyntheticProjectStateParticipant();
    $registry->registerParticipant($provider);
    $registry->replaceStates(['fixture/progress' => ['resourceId' => 'resource-a', 'count' => 4]]);
    $pipeline = new SaveCompatibilityPipeline(createProjectStateManifest(), projectStateRegistry: $registry);
    $config = createProjectStateConfig(['fixture/progress' => ['version' => 1, 'data' => ['oldId' => 'resource-b', 'count' => 10]]]);
    $loaded = $pipeline->load(serialize(createProjectStateEnvelope($config)), '/unused');
    expect($loaded->config->projectState['fixture/progress']['data']['count'])->toBe(10)
        ->and($registry->getState('fixture/progress')['count'])->toBe(4);
    $provider->failMigration = true;
    expect(fn() => $pipeline->load(serialize(createProjectStateEnvelope($config)), '/unused'))->toThrow(ProjectStateException::class);
    $invalid = createProjectStateConfig(['fixture/progress' => ['version' => 2, 'data' => ['resourceId' => 'removed-resource', 'count' => 1]]]);
    expect(fn() => $pipeline->load(serialize(createProjectStateEnvelope($invalid)), '/unused'))->toThrow(ProjectStateException::class, 'reference');
    expect($registry->getState('fixture/progress')['count'])->toBe(4);
});

it('resets previous campaign progress for another save and for a new campaign', function () {
    $game = new ProjectStateTestGame();
    $game->registerProjectStateParticipant(new SyntheticProjectStateParticipant());
    $scene = new ProjectStateSceneProbe($game);
    $scene->configure(createProjectStateConfig(['fixture/progress' => ['version' => 2, 'data' => ['resourceId' => 'resource-a', 'count' => 12]],
        'unavailable/module' => ['version' => 1, 'data' => ['oldCampaign' => true]]]));
    $scene->configure(createProjectStateConfig(['fixture/progress' => ['version' => 2, 'data' => ['resourceId' => 'resource-b', 'count' => 3]]]));
    expect($scene->seenState['count'])->toBe(3)->and($game->getProjectStateRegistry()->diagnostics)->toBe([]);
    $scene->configure(createProjectStateConfig());
    expect($scene->seenState)->toBe([])->and($scene->createSnapshot()->projectState)->toBe(['fixture/progress' => ['version' => 2, 'data' => []]]);
});

it('does not enter field hydration on policy failure and rolls back project state on later setup failure', function () {
    $game = new ProjectStateTestGame();
    $game->registerProjectStateParticipant(new SyntheticProjectStateParticipant());
    $scene = new ProjectStateSceneProbe($game);
    $scene->configure(createProjectStateConfig(['fixture/progress' => ['version' => 2, 'data' => ['resourceId' => 'resource-a', 'count' => 5]]]));
    $before = $game->getProjectStateRegistry()->captureSnapshot();
    $invalid = createProjectStateConfig(['fixture/progress' => ['version' => 2, 'data' => ['resourceId' => 'unknown', 'count' => 1]]]);
    expect(fn() => $scene->configure($invalid))->toThrow(ProjectStateException::class)
        ->and($scene->configurationCount)->toBe(1);
    $scene->failConfiguration = true;
    expect(fn() => $scene->configure(createProjectStateConfig()))->toThrow(RuntimeException::class, 'setup failed');
    expect($game->getProjectStateRegistry()->captureSnapshot())->toBe($before);
});

it('includes live project state in manual quick and autosave snapshots without weakening active-event guards', function () {
    $root = createTestDirectory('project-state-save-');
    $game = new ProjectStateTestGame($root);
    $game->registerProjectStateParticipant(new SyntheticProjectStateParticipant());
    $scene = new ProjectStateSceneProbe($game);
    $scene->configure(createProjectStateConfig());
    $registry = $game->getProjectStateRegistry();
    $manager = new SaveManager($game, 'saves', 'saves/quick', createProjectStateManifest());
    foreach (['save', 'quickSave', 'autoSave'] as $index => $method) {
        $registry->replaceStates(['fixture/progress' => ['resourceId' => 'resource-a', 'count' => $index + 1]]);
        $slot = $method === 'save' ? $manager->save($scene, 1) : $manager->$method($scene);
        $saved = $manager->loadSaveFile($slot->path);
        expect($saved->config->projectState['fixture/progress']['data']['count'])->toBe($index + 1);
    }
    $scene->unstable = true;
    foreach (['save', 'quickSave', 'autoSave'] as $method) {
        expect(fn() => $method === 'save' ? $manager->save($scene, 1) : $manager->$method($scene))->toThrow(ActiveEventSaveException::class);
    }
});

it('validates through GameLoader without hydrating the previous campaign', function () {
    $root = createTestDirectory('project-state-loader-');
    $game = new ProjectStateTestGame($root);
    $game->registerProjectStateParticipant(new SyntheticProjectStateParticipant());
    $game->getProjectStateRegistry()->replaceStates(['fixture/progress' => ['resourceId' => 'resource-a', 'count' => 6]]);
    $manager = new SaveManager($game, 'saves', 'saves/quick', createProjectStateManifest());
    $previousManager = new ReflectionProperty(SaveManager::class, 'instance')->getValue();
    new ReflectionProperty(SaveManager::class, 'instance')->setValue(null, $manager);
    try {
        $scene = new ProjectStateSceneProbe($game);
        $scene->configure(createProjectStateConfig(['fixture/progress' => ['version' => 2, 'data' => ['resourceId' => 'resource-b', 'count' => 10]]]));
        $slot = $manager->save($scene, 1);
        $game->getProjectStateRegistry()->replaceStates(['fixture/progress' => ['resourceId' => 'resource-a', 'count' => 6]]);
        $loader = new ReflectionClass(GameLoader::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(GameLoader::class, 'game')->setValue($loader, $game);
        $config = $loader->loadSavedGame($slot->path);
        expect($config->projectState['fixture/progress']['data']['count'])->toBe(10)
            ->and($game->getProjectStateRegistry()->getState('fixture/progress')['count'])->toBe(6);
    } finally {
        new ReflectionProperty(SaveManager::class, 'instance')->setValue(null, $previousManager);
    }
});

it('uses project policies in direct save readers and slot discovery without campaign hydration', function () {
    $root = createTestDirectory('project-state-direct-reader-');
    $game = new ProjectStateTestGame($root);
    $game->registerProjectStateParticipant(new SyntheticProjectStateParticipant());
    $registry = $game->getProjectStateRegistry();
    $registry->replaceStates(['fixture/progress' => ['resourceId' => 'resource-a', 'count' => 3]]);
    $manager = new SaveManager($game, 'saves', 'saves/quick', createProjectStateManifest());
    $path = $manager->getSlotPath(1);
    mkdir(dirname($path), 0700, true);
    $invalid = createProjectStateConfig(['fixture/progress' => ['version' => 2,
        'data' => ['resourceId' => 'removed-resource', 'count' => 7]]]);
    file_put_contents($path, 'IED1' . gzencode(serialize(createProjectStateEnvelope($invalid))));
    expect(fn() => $manager->loadSaveFile($path))->toThrow(ProjectStateException::class, 'reference');
    expect($manager->getSaveSlots(1)[0]->isLoadable)->toBeFalse()
        ->and($registry->getState('fixture/progress')['count'])->toBe(3);
});

it('validates and migrates an envelope copy without mutating the source configuration or live state', function () {
    $registry = new ProjectStateRegistry();
    $registry->registerParticipant(new SyntheticProjectStateParticipant());
    $registry->replaceStates(['fixture/progress' => ['resourceId' => 'resource-a', 'count' => 2]]);
    $pipeline = new SaveCompatibilityPipeline(createProjectStateManifest(), projectStateRegistry: $registry);
    $original = createProjectStateConfig(['fixture/progress' => ['version' => 1,
        'data' => ['oldId' => 'resource-b', 'count' => 9]]]);
    $envelope = $pipeline->createEnvelope(SaveSlot::empty(1, '/synthetic'), $original);
    expect($envelope['payload']['config']->projectState['fixture/progress'])->toBe([
        'version' => 2, 'data' => ['resourceId' => 'resource-b', 'count' => 9],
    ])->and($original->projectState['fixture/progress']['version'])->toBe(1)
        ->and($registry->getState('fixture/progress')['count'])->toBe(2);
    $invalid = createProjectStateConfig(['fixture/progress' => ['version' => 2,
        'data' => ['resourceId' => 'removed-resource', 'count' => 9]]]);
    expect(fn() => $pipeline->createEnvelope(SaveSlot::empty(1, '/synthetic'), $invalid))
        ->toThrow(ProjectStateException::class, 'reference');
});

it('loads an actual synthetic new-game configuration empty and resets old progress only on configure', function () {
    $root = createTestDirectory('project-state-new-game-');
    mkdir($root . '/assets/Data/Entities', 0700, true);
    file_put_contents($root . '/assets/Data/system.php', '<?php return ' . var_export([
        'title' => 'Synthetic campaign', 'currency' => ['amount' => 0],
        'startingParty' => [], 'startingInventory' => [],
        'startingPositions' => ['player' => ['destinationMap' => 'fixture-map',
            'spawnPoint' => ['x' => 1, 'y' => 2], 'spawnSprite' => 'South']],
    ], true) . ';');
    file_put_contents($root . '/assets/Data/items.php', '<?php return [];');
    file_put_contents($root . '/assets/Data/Entities/player.php', '<?php return ' . var_export([
        'sprites' => ['north' => ['^'], 'east' => ['>'], 'south' => ['v'], 'west' => ['<']],
    ], true) . ';');
    $oldDirectory = getcwd();
    try {
        chdir($root);
        $game = new ProjectStateTestGame($root);
        $participant = new SyntheticProjectStateParticipant();
        $game->registerProjectStateParticipant($participant);
        $registry = $game->getProjectStateRegistry();
        $registry->restoreSnapshot(['fixture/progress' => ['version' => 2, 'data' => ['resourceId' => 'resource-a', 'count' => 8]],
            'unavailable/previous' => ['version' => 1, 'data' => ['receipt' => 'old-campaign']]]);
        $loader = new ReflectionClass(GameLoader::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(GameLoader::class, 'game')->setValue($loader, $game);
        new ReflectionProperty(GameLoader::class, 'actorStore')->setValue($loader, new ActorStore(definitions: []));
        new ReflectionProperty(GameLoader::class, 'itemStore')->setValue($loader, new ItemStore());
        $newGame = $loader->loadNewGame();
        expect($newGame->projectState)->toBe([])->and($registry->getState('fixture/progress')['count'])->toBe(8);
        new ProjectStateSceneProbe($game)->configure($newGame);
        expect($registry->getState('fixture/progress'))->toBe([])
            ->and($registry->diagnostics)->toBe([])->and($participant->migrations)->toBe([]);
    } finally {
        chdir($oldDirectory);
    }
});
