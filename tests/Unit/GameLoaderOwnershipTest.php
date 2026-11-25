<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityPipeline;
use Ichiloto\Engine\IO\SaveCompatibility\SaveHydrationContext;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\{ActorStore, ClassStore, ItemStore};
use Tests\Support\PassiveGame;

beforeEach(function () {
    $this->previousDirectory = getcwd();
    $this->previousStatics = [];
    foreach ([ConfigStore::class, Debug::class, ClassStore::class, SkillCatalog::class, StateRegistry::class] as $class) {
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

function createOwnedLoaderProject(string $id, int $balance, array $actorData = []): array
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
            'attack' => 8, 'defence' => 7, 'magicAttack' => 6, 'magicDefence' => 5, 'speed' => 4, 'grace' => 3, 'evasion' => 2],
        ...$actorData]);
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

it('restores a slot through its owning Game after another project becomes current', function () {
    $a = createOwnedLoaderProject('a', 11, [
        'class' => 'Sentinel', 'initialEquipment' => ['weapon' => 'fixture.blade'],
        'magic' => ['learnables' => [['skill' => 'Mend', 'trainingHours' => 9]]],
    ]);
    $b = createOwnedLoaderProject('b', 29);
    foreach ([[$a, 'First', 3], [$b, 'Second', 8]] as [$project, $name, $cost]) {
        $dataRoot = $project['root'] . '/assets/Data';
        mkdir($dataRoot . '/Skills');
        file_put_contents($dataRoot . '/classes.php', '<?php return ' . var_export([
            ['name' => 'Sentinel', 'note' => $name, 'skillsToLearn' => [['level' => 1, 'skill' => 'Strike']]],
        ], true) . ';');
        file_put_contents($dataRoot . '/states.php', '<?php return ' . var_export([
            ['id' => 'fatigue', 'name' => $name, 'persistsAfterBattle' => true],
        ], true) . ';');
        foreach (['Strike' => 'special', 'Mend' => 'magic'] as $skill => $kind) {
            file_put_contents($dataRoot . '/Skills/' . strtolower($skill) . '.php', '<?php return ' . var_export([
                'class' => Skill::class, 'data' => ['name' => $skill, 'kind' => $kind, 'description' => $name,
                    'icon' => '', 'cost' => $cost, 'cooldown' => 0, 'occasion' => 'Always',
                    'scope' => ['side' => 'Enemy', 'number' => 'One', 'status' => 'Alive'],
                    'invocation' => ['message' => '$1 uses $2', 'speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 0],
                    'effects' => []],
            ], true) . ';');
        }
        $project['items']->set('fixture.blade', new Weapon($name . ' Blade', '', '!', 1, id: 'fixture.blade'));
    }

    chdir($a['root']);
    $manifest = SaveCompatibilityManifest::fromArray('synthetic-a', ['contentVersion' => 0]);
    $manager = new SaveManager($a['game'], compatibilityManifest: $manifest);
    $a['game']->bindSaveManager($manager);
    $slot = new SaveSlot(1, $a['root'] . '/slot.iedata', false, 'Map A');

    chdir($b['root']);
    ConfigStore::put(ActorStore::class, $b['actors']);
    ConfigStore::put(ItemStore::class, $b['items']);
    expect(ClassStore::all()['sentinel']['note'])->toBe('Second');
    $config = $a['loader']->loadNewGame();
    $member = $config->party->members->toArray()[0];
    $spell = SkillCatalog::getProjectCatalog($a['loader']->assetRoot)->findSkill('Mend');
    $member->spellbook->addLearnedSpell($spell);
    $member->addState(StateRegistry::get('fatigue', $a['loader']->assetRoot), guaranteed: true);
    $envelope = new SaveCompatibilityPipeline($manifest)->createEnvelope($slot, $config);
    $payload = serialize($envelope);
    expect($payload)->not->toContain($a['loader']->assetRoot);
    $bytes = 'IED1' . gzencode($payload);
    file_put_contents($slot->path, $bytes);

    $restored = $manager->loadSaveFile($slot->path)->config;
    $actor = $restored->party->members->toArray()[0];

    expect($actor->actorId)->toBe('actor.a')
        ->and($actor->role->note)->toBe('First')
        ->and($actor->abilityBook->getLearnedAbilities()[0]->cost)->toBe(3)
        ->and($actor->spellbook->getLearnedSpells()[0]->cost)->toBe(3)
        ->and($actor->spellbook->getLearnableSpells()[0]->skill->description)->toBe('First')
        ->and($actor->spellbook->getLearnableSpells()[0]->trainingHours)->toBe(9)
        ->and($actor->states[0]->state->name)->toBe('First')
        ->and($actor->equipment[0]->equipment->name)->toBe('First Blade')
        ->and($restored->party->inventory->getQuantity('item.a'))->toBe(2)
        ->and($a['loader']->loadSavedGame($slot->path)->party->inventory->getQuantity('item.a'))->toBe(2)
        ->and(getcwd())->toBe($b['root'])
        ->and(ConfigStore::get(ActorStore::class))->toBe($b['actors'])
        ->and(ConfigStore::get(ItemStore::class))->toBe($b['items'])
        ->and(ClassStore::all()['sentinel']['note'])->toBe('Second')
        ->and(file_get_contents($slot->path))->toBe($bytes);
});

it('defers the historical character callback before resolving project state references', function () {
    $oldData = json_encode(['actorId' => 'actor.a', 'states' => [['id' => 'retired-state']]], JSON_THROW_ON_ERROR);
    $actor = new ReflectionClass(Character::class)->newInstanceWithoutConstructor();
    SaveHydrationContext::begin();
    try {
        $actor->unserialize($oldData);
        expect($actor->getDeferredSaveData())->toBe(json_decode($oldData, true, flags: JSON_THROW_ON_ERROR));
    } finally {
        SaveHydrationContext::end();
    }
});
