<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\{ActorStore, ItemStore};
use Tests\Support\PassiveGame;

beforeEach(function () {
    $this->previousDirectory = getcwd();
    $this->previousStatics = [];
    foreach ([ConfigStore::class, Debug::class] as $class) {
        $this->previousStatics[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    Debug::configure(['log_directory' => getTestTemporaryDirectory()]);
});

afterEach(function () {
    chdir($this->previousDirectory);
    foreach ($this->previousStatics as $class => $properties) {
        foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
    }
});

function createOwnedLoaderProject(string $id, int $balance): array
{
    $root = createTestDirectory('owned-loader-' . $id . '-');
    mkdir($root . '/assets/Data/Entities', 0700, true);
    $system = ['title' => 'Synthetic ' . $id, 'currency' => ['amount' => $balance],
        'startingParty' => ['actor.' . $id], 'startingInventory' => [['item' => 'item.' . $id, 'quantity' => 2]],
        'startingPositions' => ['player' => ['destinationMap' => 'map.' . $id,
            'spawnPoint' => ['x' => 2, 'y' => 3], 'spawnSprite' => 'West']]];
    file_put_contents($root . '/assets/Data/system.php', '<?php return ' . var_export($system, true) . ';');
    $sprites = ['sprites' => ['north' => ['^'], 'east' => ['>'], 'south' => ['v'], 'west' => [$id]]];
    file_put_contents($root . '/assets/Data/Entities/player.php', '<?php return ' . var_export($sprites, true) . ';');
    $actor = ActorDefinition::fromArray(['id' => 'actor.' . $id, 'name' => 'Synthetic ' . $id, 'currentExp' => 0,
        'stats' => ['currentHp' => 40, 'currentMp' => 10, 'currentAp' => 3, 'totalHp' => 100, 'totalMp' => 20, 'totalAp' => 3,
            'attack' => 8, 'defence' => 7, 'magicAttack' => 6, 'magicDefence' => 5, 'speed' => 4, 'grace' => 3, 'evasion' => 2]]);
    $actors = new ActorStore(definitions: [$actor]);
    $items = new class extends ItemStore { public function __construct() {} };
    $items->set('item.' . $id, new Item('Synthetic ' . $id, 'Fixture', '!', 1, id: 'item.' . $id));
    $game = new PassiveGame();
    $loader = createTestGameLoader($game, $actors, $items, $root . '/assets');
    $game->bindGameLoader($loader);
    return compact('root', 'system', 'sprites', 'actors', 'items', 'game', 'loader');
}

it('keeps each Game loader, stores and project files when another project becomes current', function () {
    $a = createOwnedLoaderProject('a', 11);
    $b = createOwnedLoaderProject('b', 29);
    expect(GameLoader::getInstance($a['game']))->toBe($a['loader'])
        ->and(GameLoader::getInstance($b['game']))->toBe($b['loader'])
        ->and($a['loader'])->not->toBe($b['loader']);
    foreach ([[$b, $a], [$a, $b]] as [$current, $other]) {
        chdir($current['root']);
        ConfigStore::put(ActorStore::class, $current['actors']);
        ConfigStore::put(ItemStore::class, $current['items']);
        $loaded = GameLoader::getInstance($other['game'])->loadNewGame();
        $id = substr($other['system']['startingParty'][0], -1);
        expect($loaded->mapId)->toBe('map.' . $id)
            ->and($loaded->party->members->toArray()[0]->actorId)->toBe('actor.' . $id)
            ->and($loaded->party->accountBalance)->toBe($other['system']['currency']['amount'])
            ->and($loaded->party->inventory->getQuantity('item.' . $id))->toBe(2)
            ->and($loaded->playerSprite)->toBe([$id])
            ->and(getcwd())->toBe($current['root']);
    }
});

it('reads current replaceable project files through the owning root', function () {
    $p = createOwnedLoaderProject('a', 11);
    $before = $p['loader']->loadNewGame();
    $p['system']['currency']['amount'] = 43;
    $p['sprites']['sprites']['west'] = ['<'];
    file_put_contents($p['root'] . '/assets/Data/system.php', '<?php return ' . var_export($p['system'], true) . ';');
    file_put_contents($p['root'] . '/assets/Data/Entities/player.php', '<?php return ' . var_export($p['sprites'], true) . ';');
    $after = $p['loader']->loadNewGame();
    expect($before->party->accountBalance)->toBe(11)->and($before->playerSprite)->toBe(['a'])
        ->and($after->party->accountBalance)->toBe(43)->and($after->playerSprite)->toBe(['<']);
});

it('rejects a foreign owner or a second project binding', function () {
    $p = createOwnedLoaderProject('a', 11);
    $other = new PassiveGame();
    expect(fn() => $other->bindGameLoader($p['loader']))->toThrow(LogicException::class)
        ->and(fn() => $p['game']->bindGameLoader($p['loader']))->toThrow(LogicException::class)
        ->and(fn() => $other->getGameLoader())->toThrow(LogicException::class);
});

it('does not retain retired Games through a global loader registry', function () {
    $game = new PassiveGame();
    $loader = createTestGameLoader($game);
    $game->bindGameLoader($loader);
    $weak = WeakReference::create($game);
    unset($loader, $game);
    gc_collect_cycles();
    expect($weak->get())->toBeNull();
});


it('keeps project catalogue bindings out of saved inventory and rebinds after hydration', function () {
    $a = createOwnedLoaderProject('a', 11);
    $b = createOwnedLoaderProject('b', 29);
    $inventory = $a['loader']->loadNewGame()->party->inventory;
    $bytes = serialize($inventory);
    expect($bytes)->not->toContain(ItemStore::class);
    ConfigStore::put(ItemStore::class, $a['items']);
    $restored = unserialize($bytes);
    $restored->bindItemStore($a['items']);
    ConfigStore::put(ItemStore::class, $b['items']);
    expect($restored->getQuantity('item.a'))->toBe(2)
        ->and(fn() => $restored->bindItemStore($b['items']))->toThrow(LogicException::class);
});
