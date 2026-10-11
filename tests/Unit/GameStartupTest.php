<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameStartupMode;
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
}

final class StartupFieldProbe extends GameScene
{
    public array $received = [];
    public function __construct(private Game $owner) {}
    public function configure(SceneConfigurationInterface $config): void { $this->received[] = $config; }
    public function getGame(): Game { return $this->owner; }
}

final class StartupScenesProbe extends SceneManager
{
    public array $loads = [];
    public ?GameScene $field = null;
    public function __construct() {}
    public function loadScene(string|int $index, bool $suspendCurrent = false): self
    {
        $this->loads[] = $index;
        $this->currentScene = $index === GameScene::class ? $this->field : null;
        return $this;
    }
}

beforeEach(function () {
    $this->environment = getenv(GameStartupMode::ENVIRONMENT_VARIABLE);
    putenv(GameStartupMode::ENVIRONMENT_VARIABLE);
    $this->oldDirectory = getcwd();
    $this->root = createTestDirectory('ichiloto-start-mode-');
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
    foreach ([ConfigStore::class, GameLoader::class, SceneManager::class] as $class) {
        $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
    }
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
    foreach ($this->statics as $class => $properties) {
        foreach ($properties as $name => $value) {
            new ReflectionProperty($class, $name)->setValue(null, $value);
        }
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
