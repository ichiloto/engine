<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameStartupMode;
use Ichiloto\Engine\Core\GameLaunchContext;
use Ichiloto\Engine\Core\GameStartLocation;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Progress\AchievementManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Game\States\GameSceneState;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\IO\LaunchDataRoot;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\Scenes\Arena\ArenaScene;
use Ichiloto\Engine\Scenes\Arena\ProjectBattleTest;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;
use Ichiloto\Engine\Scenes\Arena\BattleTestMember;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Util\Config\PlayerSettings;
use Ichiloto\Engine\Core\Menu\Commands\MenuCommandExecutionContext;
use Ichiloto\Engine\Core\Menu\Commands\NewGameCommand;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Symfony\Component\Console\Output\BufferedOutput;

final class StartupGameProbe extends Game
{
    public function __construct(SceneManager $manager, array $options = [])
    {
        $this->sceneManager = $manager;
        $this->options = $options;
        new ReflectionProperty(Game::class, 'startupMode')->setValue($this, null);
    }
    public function __destruct() {}
    public function beginAt(?GameStartupMode $mode): void
    {
        $this->validateStartupMode($mode);
        $this->startInitialScene($mode);
    }
    public function beginSession(): void { parent::start(); }
    public function prepareContext(?GameLaunchContext $context = null): void { $this->prepareLaunchContext($context); }
    public function beginLaunch(GameLaunchContext $context): void
    {
        $this->prepareLaunchContext($context);
        $this->preflightLaunchContext();
        $this->startInitialScene(new ReflectionProperty(Game::class, 'resolvedStartupMode')->getValue($this));
    }
}

final class StartupFieldProbe extends GameScene
{
    public array $received = [];
    public array $savedConfigurations = [];
    public function __construct(private Game $owner) {}
    public function configure(SceneConfigurationInterface $config): void { $this->received[] = $config; }
    public function configureSavedGame(GameConfig $config): void
    {
        $this->savedConfigurations[] = $config;
        $this->configure($config);
    }
    public function getGame(): Game { return $this->owner; }
    public function createSnapshot(int $playTimeSeconds = 0): GameConfig { return $this->received[array_key_last($this->received)]; }
    public function configureOccupancy(GameConfig $config, array $npcs): void
    {
        $this->gameState = GameState::fromArray($config->gameState);
        $this->party = $config->party;
        $this->questManager = new QuestManager($this->owner, $this);
        $this->questManager->hydrate($config->questLog);
        $this->npcManager = new NpcManager($this);
        $this->npcManager->configure($npcs);
    }
}

final class StartupScenesProbe extends SceneManager
{
    public array $loads = [];
    public ?GameScene $field = null;
    public ?ProjectBattleTest $receivedBattleTest = null;
    public function __construct() {}
    public function loadScene(string|int $index, bool $suspendCurrent = false): self
    {
        $this->loads[] = $index;
        if ($index === ArenaScene::class) {
            $this->receivedBattleTest = $this->game->consumeLaunchBattleTest();
        }
        $this->currentScene = $index === GameScene::class ? $this->field : null;
        return $this;
    }
    public function findScene(string $className): ?\Ichiloto\Engine\Scenes\Interfaces\SceneInterface
    {
        return $className === GameScene::class ? $this->field : null;
    }
}

/** Exercises real field initialization and map commit, replacing only output/input-facing work. */
final class StartupArrivalFieldProbe extends GameScene
{
    public array $arrival = [];
    public function __construct(private Game $owner, SceneManager $manager)
    {
        $this->sceneManager = $manager;
        $this->uiManager = new class($owner) extends UIManager {
            public function __construct(Game $game) { parent::__construct($game); }
        };
        $this->camera = new Camera($this, 8, 4);
    }
    public function getGame(): Game { return $this->owner; }
    public function initializeGameSceneStates(): void { $this->fieldState = new FieldState(new SceneStateContext($this)); }
    protected function createPlayer(GameConfig $config): Player
    {
        return new class($this, 'Player', $config->playerPosition, $config->playerShape, $config->playerSprite, $config->playerHeading) extends Player {
            public int $automaticEvaluations = 0;
            public function activate(): void { $this->isActive = true; }
            public function evaluateAutomaticTriggersAtCurrentPosition(): void { $this->automaticEvaluations++; }
        };
    }
    public function setState(GameSceneState $state): void { $this->state = $state; }
    public function refreshFieldMusic(bool $force = false, bool $keepSilence = false): void {}
    public function restoreFieldAfterOverlay(): void {}
    public function reconcileFieldPresentation(): void {}
    public function onEventSessionStarted(EventExecutionSession $session): void
    {
        $this->arrival = ['map' => $this->currentMapId, 'visited' => $this->gameState->hasVisitedMap($this->currentMapId),
            'position' => [$this->player->position->x, $this->player->position->y], 'active' => $this->player->isActive,
            'npcs' => count($this->npcManager->npcs), 'automatic' => $this->player->automaticEvaluations];
        parent::onEventSessionStarted($session);
    }
}

beforeEach(function () {
    $this->environment = getenv(GameStartupMode::ENVIRONMENT_VARIABLE);
    putenv(GameStartupMode::ENVIRONMENT_VARIABLE);
    $this->launchEnvironment = getenv(GameLaunchContext::ENVIRONMENT_VARIABLE);
    putenv(GameLaunchContext::ENVIRONMENT_VARIABLE);
    $this->oldDirectory = getcwd();
    $this->root = realpath(createTestDirectory('ichiloto-start-mode-'));
    mkdir($this->root . '/assets/Data/Entities', 0700, true);
    mkdir($this->root . '/.data', 0700);
    file_put_contents($this->root . '/.data/normal-save.iedata', 'untouched save sentinel');
    file_put_contents($this->root . '/assets/Data/items.php', '<?php return [];');
    file_put_contents($this->root . '/assets/Data/system.php', '<?php return ' . var_export([
        'title' => 'Startup fixture', 'currency' => ['amount' => 123],
        'startingParty' => [], 'startingInventory' => [],
        'startingPositions' => ['player' => ['destinationMap' => 'author-selected-map',
            'spawnPoint' => ['x' => 37, 'y' => 19], 'spawnSprite' => 'West']],
    ], true) . ';');
    file_put_contents($this->root . '/assets/Data/Entities/player.php', '<?php return ' . var_export([
        'sprites' => ['north' => ['^'], 'east' => ['>'], 'south' => ['v'], 'west' => ['<']],
    ], true) . ';');
    chdir($this->root);
    $this->statics = [];
    foreach ([ConfigStore::class, GameLoader::class, SceneManager::class, MapManager::class, QuestManager::class,
        Time::class, AchievementManager::class, Debug::class, SaveManager::class] as $class) {
        $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    new ReflectionProperty(QuestManager::class, 'current')->setValue(null, null);
    new ReflectionProperty(MapManager::class, 'instance')->setValue(null, null);
    new ReflectionProperty(SaveManager::class, 'instance')->setValue(null, null);
    $this->manager = new StartupScenesProbe();
    $this->game = new StartupGameProbe($this->manager);
    new ReflectionProperty(SceneManager::class, 'game')->setValue($this->manager, $this->game);
    $this->manager->field = new StartupFieldProbe($this->game);
    $this->loader = new ReflectionClass(GameLoader::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(GameLoader::class, 'game')->setValue($this->loader, $this->game);
    new ReflectionProperty(GameLoader::class, 'actorStore')->setValue($this->loader, new ActorStore(definitions: []));
    new ReflectionProperty(GameLoader::class, 'itemStore')->setValue($this->loader, new ItemStore());
    new ReflectionProperty(GameLoader::class, 'instance')->setValue(null, $this->loader);
    new ReflectionProperty(SceneManager::class, 'instance')->setValue(null, $this->manager);
});

afterEach(function () {
    chdir($this->oldDirectory);
    putenv($this->environment === false ? GameStartupMode::ENVIRONMENT_VARIABLE
        : GameStartupMode::ENVIRONMENT_VARIABLE . '=' . $this->environment);
    putenv($this->launchEnvironment === false ? GameLaunchContext::ENVIRONMENT_VARIABLE
        : GameLaunchContext::ENVIRONMENT_VARIABLE . '=' . $this->launchEnvironment);
    foreach ($this->statics as $class => $properties) {
        foreach ($properties as $name => $value) {
            new ReflectionProperty($class, $name)->setValue(null, $value);
        }
    }
});

function writeLaunchMap(string $root, array $npcs = [['id' => 'guide', 'name' => 'Guide', 'x' => 2, 'y' => 0]]): void
{
    mkdir($root . '/assets/Maps/region/field', 0700, true);
    file_put_contents($root . '/assets/Maps/collisions.php', '<?php return [" " => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::NONE, "#" => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::SOLID];');
    file_put_contents($root . '/assets/Maps/region/field/field.data.php', '<?php return ' . var_export([
        'name' => 'Fixture', 'events' => [], 'npcs' => $npcs,
    ], true) . ';');
    file_put_contents($root . '/assets/Maps/region/field/field.map.php', "<?php return <<<'GRID'\n # \n   \nGRID;\n");
    file_put_contents($root . '/assets/Maps/region/field/field.event.php', "<?php return <<<'GRID'\n   \n   \nGRID;\n");
}

it('consumes the bounded JSON launch transport without changing working directory', function () {
    $data = realpath(createTestDirectory('launch-data-'));
    $json = json_encode(['mode' => 'new-game', 'dataRoot' => $data,
        'start' => ['mapId' => 'region/field', 'x' => 0, 'y' => 1], 'cinematicId' => 'fixture']);
    putenv(GameLaunchContext::ENVIRONMENT_VARIABLE . '=' . $json);
    $_ENV[GameLaunchContext::ENVIRONMENT_VARIABLE] = $json;
    $_SERVER[GameLaunchContext::ENVIRONMENT_VARIABLE] = $json;
    $context = GameLaunchContext::consumeEnvironment();
    expect($context->mode)->toBe(GameStartupMode::NEW_GAME)
        ->and($context->start)->toEqual(new GameStartLocation('region/field', 0, 1))
        ->and($context->cinematicId)->toBe('fixture')->and($context->dataRoot)->toBe($data)
        ->and(getcwd())->toBe($this->root)
        ->and(getenv(GameLaunchContext::ENVIRONMENT_VARIABLE))->toBeFalse()
        ->and(isset($_ENV[GameLaunchContext::ENVIRONMENT_VARIABLE]))->toBeFalse()
        ->and(isset($_SERVER[GameLaunchContext::ENVIRONMENT_VARIABLE]))->toBeFalse()
        ->and(GameLaunchContext::consumeEnvironment())->toBeNull();
});

it('keeps storage-only launch independent of default custom and legacy startup intent', function (array $options, ?string $legacyMode, string|int $expected) {
    $data = realpath(createTestDirectory('launch-data-'));
    if ($legacyMode !== null) {
        putenv(GameStartupMode::ENVIRONMENT_VARIABLE . '=' . $legacyMode);
    }
    $game = new StartupGameProbe($this->manager, $options);
    $game->beginLaunch(new GameLaunchContext(dataRoot: $data));
    expect($this->manager->loads)->toBe([$expected])
        ->and($game->getDataRoot())->toBe($data)
        ->and(new ReflectionProperty(Game::class, 'pendingLaunchContext')->getValue($game))->toBeNull();
    if ($legacyMode === 'new-game') {
        expect($this->manager->field->received[0]->mapId)->toBe('author-selected-map');
    } else {
        expect($this->manager->field->received)->toBe([]);
    }
})->with([[[], null, 0], [['starting_scene' => 4], null, 4],
    [['starting_scene' => 'CustomScene'], null, 'CustomScene'],
    [[], 'title', TitleScene::class], [[], 'new-game', GameScene::class]]);

it('refuses malformed transport and clears it before native acquisition', function (string $json) {
    putenv(GameLaunchContext::ENVIRONMENT_VARIABLE . '=' . $json);
    $console = new ReflectionClass(Console::class)->getStaticProperties();
    expect(fn() => $this->game->beginSession())->toThrow(InvalidArgumentException::class)
        ->and(getenv(GameLaunchContext::ENVIRONMENT_VARIABLE))->toBeFalse()
        ->and($this->manager->loads)->toBe([])
        ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($console);
})->with(['', '{', '[]', 'null', '{"mode":"continue"}', '{"mode":null}', '{"config":{}}',
    '{"mode":"title","start":{"mapId":"field","x":0,"y":0}}',
    '{"mode":"new-game","start":{"mapId":"../escape","x":0,"y":0}}',
    '{"mode":"new-game","start":{"mapId":"field","x":0.5,"y":0}}',
    '{"mode":"new-game","start":{"mapId":"field","x":0,"y":0,"extra":true}}',
    '{"cinematicId":"opening"}', '{"mode":"title","battleTest":{}}', '{"battleTest":[]}',
    str_repeat(' ', GameLaunchContext::MAX_TRANSPORT_BYTES + 1)]);

it('keeps default title and authored new-game startup available through the same launch contract', function (?GameStartupMode $mode, string|int $scene) {
    $this->game->beginLaunch(new GameLaunchContext(mode: $mode));
    expect($this->manager->loads)->toBe([$scene]);
    if ($mode === GameStartupMode::NEW_GAME) {
        expect($this->manager->field->received[0]->mapId)->toBe('author-selected-map');
    } else {
        expect($this->manager->field->received)->toBe([]);
    }
})->with([[null, 0], [GameStartupMode::TITLE, TitleScene::class], [GameStartupMode::NEW_GAME, GameScene::class]]);

it('parses an empty unsaved battle override as a real test rather than persisted fallback', function () {
    putenv(GameLaunchContext::ENVIRONMENT_VARIABLE . '={"battleTest":{}}');
    $context = GameLaunchContext::consumeEnvironment();
    expect($context->battleTest)->toBeInstanceOf(ProjectBattleTest::class)
        ->and($context->battleTest->toArray())->toBe([])->and($context->mode)->toBeNull();
});

it('refuses competing startup instructions before native acquisition', function () {
    putenv(GameStartupMode::ENVIRONMENT_VARIABLE . '=title');
    expect(fn() => $this->game->prepareContext(new GameLaunchContext(mode: GameStartupMode::NEW_GAME)))
        ->toThrow(InvalidArgumentException::class, 'compete');
    putenv(GameStartupMode::ENVIRONMENT_VARIABLE);
    putenv(GameLaunchContext::ENVIRONMENT_VARIABLE . '={"mode":"title"}');
    expect(fn() => $this->game->prepareContext(new GameLaunchContext(mode: GameStartupMode::TITLE)))
        ->toThrow(InvalidArgumentException::class, 'not both');
    $custom = new StartupGameProbe($this->manager, ['starting_scene' => 'CustomScene']);
    expect(fn() => $custom->prepareContext(new GameLaunchContext(mode: GameStartupMode::TITLE)))
        ->toThrow(InvalidArgumentException::class, 'cannot be combined');
});

it('requires a dedicated canonical data root and does not create or alter rejected paths', function () {
    $data = realpath(createTestDirectory('launch-data-'));
    $link = $this->root . '/linked-data';
    symlink($data, $link);
    foreach ([$this->root, $this->root . '/.data', dirname($this->root), '/', $link,
        $data . '/missing', $data . '/../' . basename($data), '.data', "bad\0path"] as $path) {
        expect(fn() => LaunchDataRoot::validate($path, $this->root))->toThrow(InvalidArgumentException::class);
    }
    file_put_contents($data . '/foreign-save', 'keep');
    expect(fn() => LaunchDataRoot::validate($data, $this->root))->toThrow(InvalidArgumentException::class)
        ->and(file_get_contents($data . '/foreign-save'))->toBe('keep');
    unlink($data . '/foreign-save');
    symlink($this->root . '/.data/normal-save.iedata', $data . '/player-settings.json');
    expect(fn() => LaunchDataRoot::validate($data, $this->root))->toThrow(InvalidArgumentException::class);
});

it('keeps original asset and save identity while settings and all save surfaces use isolated data', function () {
    putSceneAudioConfig(['save' => ['autosave' => true]]);
    $data = realpath(createTestDirectory('launch-data-'));
    $originalSettings = '{"audio":{"music":false,"sfx":false,"master_volume":0}}';
    file_put_contents($this->root . '/.data/player-settings.json', $originalSettings);
    file_put_contents($data . '/player-settings.json', $originalSettings);
    $this->game->prepareContext(new GameLaunchContext(mode: GameStartupMode::TITLE, dataRoot: $data));
    $settings = new PlayerSettings(dataRoot: $this->game->getDataRoot());
    expect($settings->get('audio.music'))->toBeFalse()->and($settings->get('audio.sfx'))->toBeFalse()
        ->and($settings->get('audio.master_volume'))->toBe(0);
    $settings->set('audio.master_volume', 25);
    $settings->persist();
    $saves = new SaveManager($this->game, compatibilityManifest: SaveCompatibilityManifest::fromArray('fixture.project', ['contentVersion' => 1]));
    expect($saves->getSlotPath(1))->toBe($data . '/saves/file-01.iedata')
        ->and($saves->getQuickSavePath('quick'))->toBe($data . '/saves/quick/quick.iedata')
        ->and($saves->getQuickSavePath('auto-01'))->toBe($data . '/saves/quick/auto-01.iedata')
        ->and($saves->getSaveFiles(true))->toBe([])
        ->and(file_get_contents($this->root . '/.data/player-settings.json'))->toBe($originalSettings)
        ->and(file_get_contents($this->root . '/.data/normal-save.iedata'))->toBe('untouched save sentinel')
        ->and(realpath(getcwd() . '/assets'))->toBe($this->root . '/assets');
    $ordinary = new StartupGameProbe($this->manager);
    expect($ordinary->getDataRoot())->toBe($this->root . '/.data');
    $this->manager->startNewGame($this->loader);
    $saves->save($this->manager->field, 1);
    $saves->quickSave($this->manager->field);
    $saves->autoSave($this->manager->field);
    expect($saves->getSaveFiles(true))->toHaveCount(3)
        ->and(scandir($this->root . '/.data'))->toBe(['.', '..', 'normal-save.iedata', 'player-settings.json']);
});

it('admits contained early launcher logs without admitting symlinks or unrelated data', function () {
    $data = realpath(createTestDirectory('launch-data-'));
    mkdir($data . '/logs', 0700);
    foreach (['php-errors.log', 'stdout.log', 'stderr.log'] as $name) {
        file_put_contents($data . '/logs/' . $name, 'early host diagnostic');
    }
    $this->game->prepareContext(new GameLaunchContext(dataRoot: $data));
    expect($this->game->getDataRoot())->toBe($data);
    foreach (['php-errors.log', 'stdout.log', 'stderr.log'] as $name) {
        expect(file_get_contents($data . '/logs/' . $name))->toBe('early host diagnostic');
    }
    symlink($this->root . '/.data/normal-save.iedata', $data . '/logs/escape.log');
    expect(fn() => LaunchDataRoot::validate($data, $this->root))->toThrow(InvalidArgumentException::class, 'symlink');
    unlink($data . '/logs/escape.log');
    mkdir($data . '/logs/nested', 0700);
    expect(fn() => LaunchDataRoot::validate($data, $this->root))->toThrow(InvalidArgumentException::class, 'regular')
        ->and(file_get_contents($this->root . '/.data/normal-save.iedata'))->toBe('untouched save sentinel');
    $linked = realpath(createTestDirectory('launch-linked-logs-'));
    symlink($data . '/logs', $linked . '/logs');
    expect(fn() => LaunchDataRoot::validate($linked, $this->root))->toThrow(InvalidArgumentException::class, 'symlink');
});

it('refuses storage escapes and late symlink replacement without touching real data', function () {
    $data = realpath(createTestDirectory('launch-data-'));
    $this->game->prepareContext(new GameLaunchContext(dataRoot: $data));
    $manifest = SaveCompatibilityManifest::fromArray('fixture.project', ['contentVersion' => 1]);
    expect(fn() => new SaveManager($this->game, saveDirectory: '../escape', compatibilityManifest: $manifest))
        ->toThrow(InvalidArgumentException::class, 'escapes');
    $saves = new SaveManager($this->game, compatibilityManifest: $manifest);
    expect(fn() => $saves->loadSaveFile($this->root . '/.data/normal-save.iedata'))
        ->toThrow(InvalidArgumentException::class, 'escapes');
    expect(fn() => $saves->getQuickSavePath('../../../escape'))
        ->toThrow(InvalidArgumentException::class, 'escapes');
    symlink($this->root . '/.data', $data . '/saves');
    expect(fn() => $saves->getSlotPath(1))->toThrow(InvalidArgumentException::class, 'symlink');
    $settings = new PlayerSettings(dataRoot: $data);
    symlink($this->root . '/.data/normal-save.iedata', $data . '/player-settings.json');
    expect(fn() => $settings->persist())->toThrow(InvalidArgumentException::class, 'symlink')
        ->and(file_get_contents($this->root . '/.data/normal-save.iedata'))->toBe('untouched save sentinel');
});

it('uses the real prepared map and consumes the cell start without rewriting definitions or later new games', function () {
    writeLaunchMap($this->root);
    $system = file_get_contents($this->root . '/assets/Data/system.php');
    $map = file_get_contents($this->root . '/assets/Maps/region/field/field.data.php');
    $this->game->beginLaunch(new GameLaunchContext(mode: GameStartupMode::NEW_GAME,
        start: new GameStartLocation('region/field', 0, 1)));
    $first = $this->manager->field->received[0];
    $this->game->beginAt(GameStartupMode::NEW_GAME);
    expect($first->mapId)->toBe('region/field')
        ->and([$first->playerPosition->x, $first->playerPosition->y])->toBe([0.0, 1.0])
        ->and($this->manager->field->received[1]->mapId)->toBe('author-selected-map')
        ->and(file_get_contents($this->root . '/assets/Data/system.php'))->toBe($system)
        ->and(file_get_contents($this->root . '/assets/Maps/region/field/field.data.php'))->toBe($map);
});

it('refuses solid occupied and out of range cells before acquiring input or output', function (int $x, int $y) {
    writeLaunchMap($this->root);
    putenv(GameLaunchContext::ENVIRONMENT_VARIABLE . '=' . json_encode(['mode' => 'new-game',
        'start' => ['mapId' => 'region/field', 'x' => $x, 'y' => $y]]));
    $console = new ReflectionClass(Console::class)->getStaticProperties();
    expect(fn() => $this->game->beginSession())->toThrow(InvalidArgumentException::class, 'launch cell')
        ->and($this->manager->loads)->toBe([])
        ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($console);
})->with([[1, 0], [2, 0], [3, 1], [0, 2]]);

it('uses shared initial NPC eligibility rather than permanently reserving authored anchors', function (array $conditions, bool $occupied) {
    $entries = [['id' => 'guide', 'name' => 'Guide', 'x' => 2, 'y' => 0,
        'movement' => 'wander', 'conditions' => $conditions]];
    writeLaunchMap($this->root, $entries);
    $config = $this->loader->loadNewGame(new GameStartLocation('region/field', 2, 0));
    $this->manager->field->configureOccupancy($config, $entries);
    expect($this->manager->field->npcManager->npcAt(2, 0) !== null)->toBe($occupied);
    $console = new ReflectionClass(Console::class)->getStaticProperties();
    $context = new GameLaunchContext(mode: GameStartupMode::NEW_GAME, start: new GameStartLocation('region/field', 2, 0));
    if ($occupied) {
        putenv(GameLaunchContext::ENVIRONMENT_VARIABLE . '=' . json_encode(['mode' => 'new-game',
            'start' => ['mapId' => 'region/field', 'x' => 2, 'y' => 0]]));
        expect(fn() => $this->game->beginSession())->toThrow(InvalidArgumentException::class, 'occupied by an NPC')
            ->and($this->manager->loads)->toBe([]);
    } else {
        $this->game->beginLaunch($context);
        expect($this->manager->loads)->toBe([GameScene::class]);
    }
    expect(new ReflectionClass(Console::class)->getStaticProperties())->toBe($console);
})->with([
    'unconditional wanderer' => [[], true],
    'inactive switch' => [[['type' => 'switch', 'name' => 'visible']], false],
    'initial false switch' => [[['type' => 'switch', 'name' => 'departed', 'value' => false]], true],
    'inactive story event' => [[['type' => 'event', 'name' => 'met-guide']], false],
    'negated initial event' => [[['type' => 'event', 'name' => 'departed', 'negate' => true]], true],
    'initial variable' => [[['type' => 'variable', 'name' => 'phase', 'value' => 0]], true],
    'later variable' => [[['type' => 'variable', 'name' => 'phase', 'op' => '>', 'value' => 0]], false],
    'all conditions required' => [[['type' => 'switch', 'name' => 'departed', 'value' => false],
        ['type' => 'event', 'name' => 'met-guide']], false],
    'fresh quest log' => [[['type' => 'quest', 'name' => 'guide-quest', 'status' => 'active']], false],
    'negated fresh quest' => [[['type' => 'quest', 'name' => 'guide-quest', 'negate' => true]], true],
]);

it('evaluates NPC item eligibility against the authored starting party inventory', function (array $condition, bool $occupied) {
    $actors = new ActorStore(definitions: [ActorDefinition::fromArray(['id' => 'fixture.hero', 'name' => 'Hero', 'currentExp' => 0,
        'stats' => ['currentHp' => 40, 'currentMp' => 10, 'currentAp' => 3, 'totalHp' => 100, 'totalMp' => 20, 'totalAp' => 3,
        'attack' => 8, 'defence' => 7, 'magicAttack' => 6, 'magicDefence' => 5, 'speed' => 4, 'grace' => 3, 'evasion' => 2]])]);
    $items = new ItemStore();
    $items->set('item.tonic', new Item('Tonic', 'Synthetic consumable.', '!', 1, id: 'item.tonic'));
    $items->set('item.sigil', new Item('Sigil', 'Synthetic key item.', 'i', 0, id: 'item.sigil', isKeyItem: true));
    ConfigStore::put(ItemStore::class, $items);
    new ReflectionProperty(GameLoader::class, 'actorStore')->setValue($this->loader, $actors);
    new ReflectionProperty(GameLoader::class, 'itemStore')->setValue($this->loader, $items);
    $system = require $this->root . '/assets/Data/system.php';
    $system['startingParty'] = ['fixture.hero'];
    $system['startingInventory'] = [['item' => 'item.tonic', 'quantity' => 2], 'item.sigil'];
    file_put_contents($this->root . '/assets/Data/system.php', '<?php return ' . var_export($system, true) . ';');
    writeLaunchMap($this->root, [['name' => 'Guide', 'x' => 2, 'y' => 0, 'conditions' => [$condition]]]);
    $context = new GameLaunchContext(mode: GameStartupMode::NEW_GAME, start: new GameStartLocation('region/field', 2, 0));
    if ($occupied) {
        expect(fn() => $this->game->beginLaunch($context))->toThrow(InvalidArgumentException::class, 'occupied by an NPC')
            ->and($this->manager->loads)->toBe([]);
    } else {
        $this->game->beginLaunch($context);
        expect($this->manager->field->received[0]->party->members->count())->toBe(1)
            ->and($this->manager->field->received[0]->party->inventory->getQuantity('item.tonic'))->toBe(2);
    }
})->with([
    'starting item threshold' => [['type' => 'item', 'name' => 'item.tonic', 'quantity' => 2], true],
    'insufficient starting items' => [['type' => 'item', 'name' => 'item.tonic', 'quantity' => 3], false],
    'starting key item' => [['type' => 'key_item', 'name' => 'item.sigil'], true],
    'plain item is not a key item' => [['type' => 'key_item', 'name' => 'item.tonic'], false],
]);

it('ignores a previously running world and quest log when validating a fresh launch', function (array $conditions) {
    $field = $this->manager->field;
    $field->configureOccupancy($this->loader->loadNewGame(), []);
    $field->gameState->setSwitch('visible');
    $field->questManager->log->accept('guide-quest', 1);
    writeLaunchMap($this->root, [['name' => 'Guide', 'x' => 2, 'y' => 0, 'conditions' => $conditions]]);
    $before = $field->gameState->toArray();
    $quests = $field->questManager->log->toArray();
    $this->game->beginLaunch(new GameLaunchContext(mode: GameStartupMode::NEW_GAME, start: new GameStartLocation('region/field', 2, 0)));
    expect($this->manager->loads)->toBe([GameScene::class])
        ->and($field->gameState->toArray())->toBe($before)->and($field->questManager->log->toArray())->toBe($quests)
        ->and($field->received[0]->gameState)->toBe([])->and($field->received[0]->questLog)->toBe([]);
})->with([
    'previous switches' => [[['type' => 'switch', 'name' => 'visible']]],
    'previous quests' => [[['type' => 'quest', 'name' => 'guide-quest', 'status' => 'active']]],
]);

it('validates current synthetic placement instead of pinning an earlier NPC position', function () {
    writeLaunchMap($this->root, [['name' => 'Guide', 'x' => 0, 'y' => 0]]);
    $this->game->beginLaunch(new GameLaunchContext(mode: GameStartupMode::NEW_GAME, start: new GameStartLocation('region/field', 2, 0)));
    expect($this->manager->loads)->toBe([GameScene::class]);
});

it('rejects missing or escaping launch IDs before native acquisition', function (string $mapId, ?string $cinematicId) {
    writeLaunchMap($this->root);
    if ($mapId === 'escape') {
        symlink(createTestDirectory('foreign-map-'), $this->root . '/assets/Maps/escape');
    }
    putenv(GameLaunchContext::ENVIRONMENT_VARIABLE . '=' . json_encode(array_filter(['mode' => 'new-game',
        'start' => ['mapId' => $mapId, 'x' => 0, 'y' => 1], 'cinematicId' => $cinematicId], static fn($v) => $v !== null)));
    expect(fn() => $this->game->beginSession())->toThrow(InvalidArgumentException::class)
        ->and($this->manager->loads)->toBe([]);
})->with([['missing', null], ['escape', null], ['region/field', 'missing']]);

it('follows contained source directories safely and rejects escaping files even through an internal directory link', function () {
    writeLaunchMap($this->root);
    mkdir($this->root . '/assets/shared', 0700);
    symlink($this->root . '/assets/shared', $this->root . '/assets/Maps/region/field/shared');
    symlink($this->root . '/assets/Maps/region/field', $this->root . '/assets/shared/cycle');
    $foreign = createTestDirectory('foreign-launch-source-');
    file_put_contents($foreign . '/outside.php', '<?php return [];');
    symlink($foreign . '/outside.php', $this->root . '/assets/shared/escape.php');
    putenv(GameLaunchContext::ENVIRONMENT_VARIABLE . '={"mode":"new-game","start":{"mapId":"region/field","x":0,"y":1}}');
    expect(fn() => $this->game->beginSession())->toThrow(InvalidArgumentException::class, 'outside project assets')
        ->and($this->manager->loads)->toBe([]);
});

it('loads current cinematic definitions without injecting events and clears one-shot state after dispatch', function () {
    writeLaunchMap($this->root);
    mkdir($this->root . '/assets/Cutscenes/Cinematics/fixture', 0700, true);
    file_put_contents($this->root . '/assets/Cutscenes/Cinematics/fixture/fixture.data.php', '<?php return ["id" => "fixture", "name" => "Current fixture"];');
    file_put_contents($this->root . '/assets/Cutscenes/Cinematics/fixture/fixture.script.php', '<?php return [["type" => "wait", "seconds" => 0.1]];');
    $map = file_get_contents($this->root . '/assets/Maps/region/field/field.data.php');
    $events = file_get_contents($this->root . '/assets/Maps/region/field/field.event.php');
    $this->game->beginLaunch(new GameLaunchContext(mode: GameStartupMode::NEW_GAME,
        start: new GameStartLocation('region/field', 0, 1), cinematicId: 'fixture'));
    expect($this->manager->field->received)->toHaveCount(1)
        ->and(new ReflectionProperty(Game::class, 'pendingLaunchContext')->getValue($this->game))->toBeNull()
        ->and(new ReflectionProperty(Game::class, 'pendingLaunchCinematic')->getValue($this->game))->toBeNull()
        ->and(new ReflectionProperty(GameScene::class, 'launchCinematic')->getValue($this->manager->field))->toBeNull()
        ->and(file_get_contents($this->root . '/assets/Maps/region/field/field.data.php'))->toBe($map)
        ->and(file_get_contents($this->root . '/assets/Maps/region/field/field.event.php'))->toBe($events);
});

it('starts the one-shot cinematic after real field arrival commits and defers automatic triggers until completion', function () {
    writeLaunchMap($this->root);
    Debug::configure(['log_directory' => $this->root . '/logs']);
    ConfigStore::put(\Ichiloto\Engine\Util\Config\PlaySettings::class,
        new \Ichiloto\Engine\Util\Config\PlaySettings(['screen' => ['width' => 8, 'height' => 4]]));
    ConfigStore::put(\Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog::class,
        \Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog::empty());
    putSceneAudioConfig(['ui' => ['hud' => ['location' => false]]]);
    new ReflectionProperty(Game::class, 'audioManager')->setValue($this->game, new RecordingAudioManager($this->game));
    $scene = new StartupArrivalFieldProbe($this->game, $this->manager);
    $this->manager->field = $scene;
    $this->manager->currentScene = $scene;
    $config = $this->loader->loadNewGame(new GameStartLocation('region/field', 0, 1));
    $cinematic = CinematicDefinition::fromArrays(['id' => 'launch', 'name' => 'Synthetic launch'],
        [['type' => 'wait', 'seconds' => 0.1]]);
    $map = file_get_contents($this->root . '/assets/Maps/region/field/field.data.php');
    $events = file_get_contents($this->root . '/assets/Maps/region/field/field.event.php');
    $scene->configureForLaunch($config, $cinematic);
    $session = $scene->eventInterpreter->activeSession();
    expect($scene->arrival)->toBe(['map' => 'region/field', 'visited' => true, 'position' => [0.0, 1.0],
        'active' => true, 'npcs' => 1, 'automatic' => 0])
        ->and($session->status)->toBe(EventExecutionStatus::YIELDED)
        ->and($scene->cinematicController->active())->toBe($cinematic)
        ->and(new ReflectionProperty(GameScene::class, 'launchCinematic')->getValue($scene))->toBeNull();
    $scene->eventInterpreter->update(1.0);
    expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
        ->and($scene->cinematicController->active())->toBeNull()->and($scene->player->automaticEvaluations)->toBe(1);
    $scene->configure($config);
    expect($scene->cinematicController->active())->toBeNull()->and($scene->eventInterpreter->activeSession())->toBeNull()
        ->and($scene->player->automaticEvaluations)->toBe(1)
        ->and(file_get_contents($this->root . '/assets/Maps/region/field/field.data.php'))->toBe($map)
        ->and(file_get_contents($this->root . '/assets/Maps/region/field/field.event.php'))->toBe($events);
});

it('launches the unsaved battle test once and preserves the original project test', function () {
    $actors = new ActorStore(definitions: [ActorDefinition::fromArray(['id' => 'fixture.hero', 'name' => 'Hero', 'currentExp' => 0,
        'stats' => ['currentHp' => 40, 'currentMp' => 10, 'currentAp' => 3, 'totalHp' => 100, 'totalMp' => 20, 'totalAp' => 3,
        'attack' => 8, 'defence' => 7, 'magicAttack' => 6, 'magicDefence' => 5, 'speed' => 4, 'grace' => 3, 'evasion' => 2]])]);
    ConfigStore::put(ActorStore::class, $actors);
    ConfigStore::put(ItemStore::class, new ItemStore());
    $system = file_get_contents($this->root . '/assets/Data/system.php');
    $test = new ProjectBattleTest(setup: new BattleTestSetup([new BattleTestMember('fixture.hero', 1)]));
    $game = new StartupGameProbe($this->manager, ['starting_scene' => ArenaScene::class]);
    $this->manager->game = $game;
    $game->beginLaunch(new GameLaunchContext(battleTest: $test));
    expect($this->manager->loads)->toBe([ArenaScene::class])
        ->and($this->manager->receivedBattleTest)->toBe($test)
        ->and($game->consumeLaunchBattleTest())->toBeNull()
        ->and(file_get_contents($this->root . '/assets/Data/system.php'))->toBe($system);
});

it('refuses invalid battle references and conflicting arena options before native startup', function () {
    ConfigStore::put(ActorStore::class, new ActorStore(definitions: []));
    ConfigStore::put(ItemStore::class, new ItemStore());
    $test = new ProjectBattleTest(setup: new BattleTestSetup([new BattleTestMember('missing', 1)]));
    expect(fn() => $this->game->beginLaunch(new GameLaunchContext(battleTest: $test)))
        ->toThrow(InvalidArgumentException::class, 'no such actor')->and($this->manager->loads)->toBe([]);
    foreach ([['starting_scene' => TitleScene::class], ['arena_troop' => 'competing'], [ArenaScene::SETUP_OPTION => $test->setup]] as $options) {
        $game = new StartupGameProbe($this->manager, $options);
        expect(fn() => $game->prepareContext(new GameLaunchContext(battleTest: $test)))
            ->toThrow(InvalidArgumentException::class, 'conflicts');
    }
});

it('parses explicit startup intent without turning absent intent into a custom-scene override', function (?string $value, ?GameStartupMode $expected) {
    putenv($value === null ? GameStartupMode::ENVIRONMENT_VARIABLE : GameStartupMode::ENVIRONMENT_VARIABLE . '=' . $value);
    expect(GameStartupMode::readEnvironment())->toBe($expected);
})->with([
    [null, null], ['', null], ['  ', null], ['new-game', GameStartupMode::NEW_GAME],
    [' TITLE ', GameStartupMode::TITLE], ['New-Game', GameStartupMode::NEW_GAME],
]);

it('rejects unknown startup intent before terminal acquisition', function (string $value) {
    putenv(GameStartupMode::ENVIRONMENT_VARIABLE . '=' . $value);
    $before = new ReflectionClass(Console::class)->getStaticProperties();
    expect(fn() => $this->game->beginSession())->toThrow(InvalidArgumentException::class, 'Unknown game start mode')
        ->and($this->manager->loads)->toBe([])
        ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($before);
})->with(['continue', 'saved-game', '0', 'new_game']);

it('preserves ordinary title and explicitly configured custom-scene startup', function (array $options, string|int $expected) {
    $game = new StartupGameProbe($this->manager, $options);
    $game->beginAt(null);
    expect($this->manager->loads)->toBe([$expected])
        ->and($this->manager->field->received)->toBe([]);
})->with([[[], 0], [['starting_scene' => 4], 4], [['starting_scene' => 'CustomScene'], 'CustomScene']]);

it('opens the real title identity without loading a new or continued game', function () {
    $this->game->beginAt(GameStartupMode::TITLE);
    expect($this->manager->loads)->toBe([TitleScene::class])
        ->and($this->manager->field->received)->toBe([])
        ->and(file_get_contents($this->root . '/.data/normal-save.iedata'))->toBe('untouched save sentinel');
});

it('refuses conflicting custom-scene and explicit startup modes before loading anything', function (GameStartupMode $mode) {
    $game = new StartupGameProbe($this->manager, ['starting_scene' => 4]);
    expect(fn() => $game->beginAt($mode))->toThrow(InvalidArgumentException::class, 'cannot be combined')
        ->and($this->manager->loads)->toBe([]);
})->with([GameStartupMode::NEW_GAME, GameStartupMode::TITLE]);

it('starts a fresh game at the authored spawn through the real loader without reading or changing saves', function () {
    $system = file_get_contents($this->root . '/assets/Data/system.php');
    $this->game->beginAt(GameStartupMode::NEW_GAME);
    $first = $this->manager->field->received[0];
    $this->game->beginAt(GameStartupMode::NEW_GAME);
    $second = $this->manager->field->received[1];
    expect($this->manager->loads)->toBe([GameScene::class, GameScene::class])
        ->and($first)->toBeInstanceOf(GameConfig::class)
        ->and($first->mapId)->toBe('author-selected-map')
        ->and([$first->playerPosition->x, $first->playerPosition->y])->toBe([37.0, 19.0])
        ->and($first->playerSprite)->toBe(['<'])
        ->and($first->party->accountBalance)->toBe(123)
        ->and($first->playTimeSeconds)->toBe(0)
        ->and($first->events)->toBe([])
        ->and($first->gameState)->toBe([])
        ->and($second->party)->not->toBe($first->party)
        ->and(file_get_contents($this->root . '/assets/Data/system.php'))->toBe($system)
        ->and(scandir($this->root . '/.data'))->toBe(['.', '..', 'normal-save.iedata'])
        ->and(file_get_contents($this->root . '/.data/normal-save.iedata'))->toBe('untouched save sentinel');
});

it('uses the same shared new-game mechanism from the title command', function () {
    $menu = $this->createStub(MenuInterface::class);
    $command = new NewGameCommand($menu, $this->loader);
    $context = new MenuCommandExecutionContext([], new BufferedOutput(), $menu, $this->manager->field);
    expect($command->execute($context))->toBe(NewGameCommand::SUCCESS)
        ->and($this->manager->loads)->toBe([GameScene::class])
        ->and($this->manager->field->received)->toHaveCount(1)
        ->and($this->manager->field->received[0]->mapId)->toBe('author-selected-map');
});

it('does not leave the current scene when new-game data is invalid', function () {
    $current = $this->manager->field;
    $this->manager->currentScene = $current;
    file_put_contents($this->root . '/assets/Data/system.php', '<?php return "invalid";');
    expect(fn() => $this->manager->startNewGame($this->loader))->toThrow(RuntimeException::class, 'not an array')
        ->and($this->manager->loads)->toBe([])
        ->and($this->manager->currentScene)->toBe($current)
        ->and($current->received)->toBe([]);
});

function writeLaunchCheckpoint(string $root, GameConfig $config, bool $legacy = false, string $project = 'fixture.launch'): string
{
    file_put_contents($root . '/ichiloto.json', json_encode(['id' => 'fixture.launch']));
    file_put_contents($root . '/assets/Data/save-compatibility.php', '<?php return ["contentVersion" => 0];');
    $path = $root . '/checkpoint.iedata';
    $slot = new \Ichiloto\Engine\IO\Saves\SaveSlot(3, $path, false, 'Fixture', 'Existing leader', 7, 532, 1234);
    $payload = $legacy ? ['slot' => $slot, 'config' => $config]
        : new \Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityPipeline(
            SaveCompatibilityManifest::fromArray($project, ['contentVersion' => 0]))->createEnvelope($slot, $config);
    file_put_contents($path, 'IED1' . gzencode(serialize($payload)));
    return $path;
}

it('imports and resumes a selected checkpoint through saved-game startup in fresh isolated storage', function (bool $legacy) {
    writeLaunchMap($this->root);
    $config = $this->loader->loadNewGame(new GameStartLocation('region/field', 0, 1));
    $snapshot = $config->getSaveCompatibilityData();
    $snapshot['events'] = ['checkpoint-story-complete'];
    $snapshot['playTimeSeconds'] = 532;
    $config->applySaveCompatibilityData($snapshot);
    $source = writeLaunchCheckpoint($this->root, $config, $legacy);
    $bytes = file_get_contents($source);
    $data = realpath(createTestDirectory('launch-checkpoint-'));
    file_put_contents($data . '/player-settings.json', '{"audio":{"music":false,"sfx":false,"voice":false,"master_volume":0}}');
    $context = GameLaunchContext::fromArray(['checkpoint' => $source, 'dataRoot' => $data]);
    $this->game->beginLaunch($context);
    $received = $this->manager->field->savedConfigurations[0];
    expect($this->manager->loads)->toBe([GameScene::class])
        ->and($received->events)->toBe($config->events)
        ->and($received->playTimeSeconds)->toBe(532)
        ->and(serialize($received->party))->toBe(serialize($config->party))
        ->and($received->mapId)->toBe('region/field')
        ->and(file_get_contents($source))->toBe($bytes)
        ->and(file_get_contents($data . '/saves/file-01.iedata'))->toBe($bytes)
        ->and(file_get_contents($this->root . '/.data/normal-save.iedata'))->toBe('untouched save sentinel')
        ->and(file_get_contents($data . '/player-settings.json'))->toContain('"music":false')
        ->and(new ReflectionProperty(Game::class, 'pendingLaunchCheckpoint')->getValue($this->game))->toBeNull();
    // The import does not relax the fresh-root guard for a later process.
    expect(fn() => LaunchDataRoot::validate($data, $this->root))->toThrow(InvalidArgumentException::class);
    $this->game->beginAt(GameStartupMode::NEW_GAME);
    expect($this->manager->field->savedConfigurations)->toHaveCount(1)
        ->and($this->manager->field->received)->toHaveCount(2);
})->with([false, true]);

it('rejects invalid or foreign checkpoint data before any scene or isolated save is created', function (string $kind, string $exception) {
    writeLaunchMap($this->root);
    $config = $this->loader->loadNewGame(new GameStartLocation('region/field', 0, 1));
    $source = writeLaunchCheckpoint($this->root, $config, project: $kind === 'foreign' ? 'another.project' : 'fixture.launch');
    if ($kind === 'corrupt') { file_put_contents($source, 'IED1invalid-gzip'); }
    if ($kind === 'symlink') { symlink($source, $source . '.link'); $source .= '.link'; }
    $bytes = file_get_contents($source);
    $data = realpath(createTestDirectory('launch-checkpoint-rejected-'));
    expect(fn() => $this->game->beginLaunch(new GameLaunchContext(dataRoot: $data, checkpoint: $source)))
        ->toThrow($exception);
    expect($this->manager->loads)->toBe([])
        ->and(is_dir($data . '/saves'))->toBeFalse()
        ->and(file_get_contents($source))->toBe($bytes)
        ->and(new ReflectionProperty(Game::class, 'pendingLaunchContext')->getValue($this->game))->toBeNull()
        ->and(new ReflectionProperty(Game::class, 'pendingLaunchCheckpoint')->getValue($this->game))->toBeNull();
})->with([
    ['foreign', \Ichiloto\Engine\Exceptions\WrongProjectSaveException::class],
    ['corrupt', \Ichiloto\Engine\Exceptions\CorruptSaveException::class],
    ['symlink', InvalidArgumentException::class],
]);

it('requires isolated storage and exclusive intent for checkpoint launch', function () {
    foreach ([['checkpoint' => '/checkpoint.iedata'], ['checkpoint' => '/checkpoint.iedata', 'dataRoot' => '/data', 'mode' => 'new-game'],
        ['checkpoint' => '/checkpoint.iedata', 'dataRoot' => '/data', 'mode' => 'title'],
        ['checkpoint' => '/checkpoint.iedata', 'dataRoot' => '/data', 'battleTest' => []],
        ['checkpoint' => 3, 'dataRoot' => '/data']] as $input) {
        expect(fn() => GameLaunchContext::fromArray($input))->toThrow(InvalidArgumentException::class);
    }
    $context = new GameLaunchContext(dataRoot: '/data', checkpoint: '/checkpoint.iedata');
    expect(fn() => $context->validateOptions([], GameStartupMode::TITLE))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $context->validateOptions(['starting_scene' => 0], null))->toThrow(InvalidArgumentException::class);
    $ordinary = new SaveManager($this->game, compatibilityManifest: SaveCompatibilityManifest::fromArray('fixture.launch', ['contentVersion' => 0]));
    expect(fn() => $ordinary->importIsolatedCheckpoint('/checkpoint.iedata'))->toThrow(InvalidArgumentException::class);
});
