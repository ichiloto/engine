<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\MapSourceReader;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;

use function Tests\Support\Rendering\writeTestPng;

require_once dirname(__DIR__) . '/Support/Rendering/GraphicalSpriteFixtures.php';

final class OccupancyMapManagerProbe extends MapManager
{
    public int $dictionaryCalls = 0;
    public int $legacyCollisionCalls = 0;
    public array $dictionary = ['#' => CollisionType::SOLID, '.' => CollisionType::NONE];

    public function __construct(GameScene $scene, public array $testPaths)
    {
        $this->gameScene = $scene;
    }

    protected function resolveMapPaths(string $filename): array
    {
        return $this->testPaths;
    }

    protected function getCollisionDictionary(): array
    {
        $this->dictionaryCalls++;
        return $this->dictionary;
    }

    public function generateLayerCollisionMap(MapLayerSet $layers, array $dictionary = []): array
    {
        $this->legacyCollisionCalls++;
        return parent::generateLayerCollisionMap($layers, $dictionary);
    }
}

function createOccupancyLoadingFixture(bool $layered = false, string $text = "##\n#."): array
{
    $root = createTestDirectory('ichiloto-occupancy-');
    $directory = $root . '/assets/Maps/test/physical';
    mkdir($directory, 0700, true);
    $paths = MapSourceReader::resolvePaths($root . '/assets/Maps', 'test/physical');
    $gridPath = $paths['map'];
    if ($layered) {
        mkdir($directory . '/layers');
        $gridPath = $directory . '/layers/01.terrain.map.php';
    }
    file_put_contents($gridPath, MapGridSource::buildSource($text, 'MAP'));
    $event = implode("\n", array_map(static fn(array $row): string => str_repeat(' ', count($row)), MapLayer::parseGrid($text)));
    file_put_contents($paths['event'], MapGridSource::buildSource($event, 'EVENT'));
    file_put_contents($paths['data'], '<?php return [];');

    // Music is a recording-only seam: these tests cannot launch any audio backend.
    $scene = new class extends GameScene {
        public int $musicCalls = 0;
        public function __construct() {}
        public function refreshFieldMusic(bool $force = false, bool $keepSilence = false): void { $this->musicCalls++; }
    };
    $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: [['old']]);
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
    new ReflectionProperty(GameScene::class, 'party')->setValue($scene, new Party());
    new ReflectionProperty(GameScene::class, 'gameState')->setValue($scene, new GameState());
    $manager = new OccupancyMapManagerProbe($scene, $paths);
    new ReflectionProperty(MapManager::class, 'tileMap')->setValue($manager, [['old']]);
    new ReflectionProperty(MapManager::class, 'collisionMap')->setValue($manager, [[CollisionType::ENCOUNTER->value]]);
    new ReflectionProperty(MapManager::class, 'mapWidth')->setValue($manager, 1);
    new ReflectionProperty(MapManager::class, 'mapHeight')->setValue($manager, 1);
    $oldLayers = new MapLayerSet([new MapLayer('terrain', 0, false, 'old', 'x')], legacy: true);
    new ReflectionProperty(MapManager::class, 'layers')->setValue($manager, $oldLayers);
    $player = new ReflectionClass(Player::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(Player::class, 'position')->setValue($player, new Vector2(0, 0));
    return [$root, $paths, $gridPath, $manager, $scene, $camera, $player];
}

it('prepares and installs explicit physical cells through the existing atomic map path', function (bool $layered) {
    [$root, $paths, $grid, $manager, $scene, $camera, $player] = createOccupancyLoadingFixture($layered);
    $rows = [[CollisionType::NONE, CollisionType::COUNTER], [CollisionType::SAVE_POINT, CollisionType::ENCOUNTER]];
    file_put_contents($paths['data'], '<?php return ' . var_export(['name' => 'Physical', 'occupancy' => $rows], true) . ';');
    $prepared = $manager->prepareMap('test/physical');
    expect($prepared->collisions)->toBe([[0, 10], [6, 7]])
        ->and($manager->tileMap)->toBe([['old']])
        ->and($manager->getCollision(0, 0))->toBe(CollisionType::ENCOUNTER)
        ->and($camera->worldSpace)->toBe([['old']])
        ->and($manager->dictionaryCalls)->toBe(0)
        ->and($manager->legacyCollisionCalls)->toBe(0)
        ->and($scene->musicCalls)->toBe(0);
    $manager->applyPreparedMap($prepared, $player);
    $collision = null;
    expect($camera->worldSpace)->toBe([['#', '#'], ['#', '.']])
        ->and($manager->canMoveTo(0, 0, $collision))->toBeTrue()
        ->and($collision)->toBe(CollisionType::NONE)
        ->and($manager->canMoveTo(1, 0, $collision))->toBeFalse()
        ->and($collision)->toBe(CollisionType::COUNTER)
        ->and($manager->isCounterAt(1, 0))->toBeTrue()
        ->and($manager->getCollision(0, 1))->toBe(CollisionType::SAVE_POINT)
        ->and($manager->getCollision(1, 1))->toBe(CollisionType::ENCOUNTER);

    file_put_contents($grid, MapGridSource::buildSource("..\n~?", 'MAP'));
    $manager->loadMap('test/physical', $player);
    expect($camera->worldSpace)->toBe([['.', '.'], ['~', '?']])
        ->and($manager->getCollision(0, 0))->toBe(CollisionType::NONE)
        ->and($manager->getCollision(1, 0))->toBe(CollisionType::COUNTER)
        ->and($manager->getCollision(0, 1))->toBe(CollisionType::SAVE_POINT)
        ->and($manager->getCollision(1, 1))->toBe(CollisionType::ENCOUNTER)
        ->and($manager->dictionaryCalls)->toBe(0);
})->with([false, true]);

it('preserves unmigrated legacy and layered passage including the default dictionary', function (bool $layered) {
    [$root, $paths, $grid, $manager] = createOccupancyLoadingFixture($layered, ";?\nx~");
    $manager->dictionary = [];
    $prepared = $manager->prepareMap('test/physical');
    expect($prepared->collisions)->toBe([[7, 6], [1, 1]])
        ->and($prepared->data)->not->toHaveKey('occupancy')
        ->and($manager->dictionaryCalls)->toBe(1)
        ->and($manager->legacyCollisionCalls)->toBe(1);
})->with([false, true]);

it('retains established movement and counter semantics for every explicit final collision type', function (CollisionType $type) {
    [$root, $paths, $grid, $manager, $scene, $camera, $player] = createOccupancyLoadingFixture(text: '#');
    file_put_contents($paths['data'], '<?php return ' . var_export(['occupancy' => [[$type]]], true) . ';');
    $manager->loadMap('test/physical', $player);
    $collision = null;
    expect($manager->getCollision(0, 0))->toBe($type)
        ->and($manager->canMoveTo(0, 0, $collision))->toBe(!in_array($type, [CollisionType::SOLID, CollisionType::NPC, CollisionType::COUNTER], true))
        ->and($collision)->toBe($type)
        ->and($manager->isCounterAt(0, 0))->toBe($type === CollisionType::COUNTER);
})->with(array_values(array_filter(CollisionType::cases(), static fn(CollisionType $type): bool => $type !== CollisionType::PASS_THROUGH)));

it('keeps live NPC occupancy separate from the explicit static physical snapshot', function () {
    [$root, $paths, $grid, $manager, $scene, $camera, $player] = createOccupancyLoadingFixture(text: '#');
    file_put_contents($paths['data'], '<?php return ' . var_export(['occupancy' => [[CollisionType::NONE]]], true) . ';');
    $manager->loadMap('test/physical', $player);
    $npcManager = new NpcManager($scene);
    new ReflectionProperty(GameScene::class, 'npcManager')->setValue($scene, $npcManager);
    $npcManager->configure([['id' => 'resident', 'name' => 'Resident', 'x' => 0, 'y' => 0]]);
    $collision = null;
    expect($manager->canMoveTo(0, 0, $collision))->toBeFalse()
        ->and($collision)->toBe(CollisionType::NPC)
        ->and($manager->getCollision(0, 0))->toBe(CollisionType::NONE);
    $npcManager->configure([]);
    expect($manager->canMoveTo(0, 0, $collision))->toBeTrue()
        ->and($collision)->toBe(CollisionType::NONE);
});

it('preserves active geometry collision camera party and NPCs when declared occupancy is invalid', function (mixed $rows, bool $layered) {
    [$root, $paths, $grid, $manager, $scene, $camera, $player] = createOccupancyLoadingFixture($layered);
    $layers = $manager->layers;
    $location = $scene->party->location;
    $npcManager = new NpcManager($scene);
    new ReflectionProperty(GameScene::class, 'npcManager')->setValue($scene, $npcManager);
    $npcManager->configure([['id' => 'resident', 'name' => 'Resident', 'x' => 0, 'y' => 0]]);
    $resident = $npcManager->npcs[0];
    file_put_contents($paths['data'], '<?php return ' . var_export(['occupancy' => $rows,
        'name' => 'Rejected', 'npcs' => [['id' => 'replacement', 'name' => 'Replacement', 'x' => 1, 'y' => 1]]], true) . ';');
    foreach (['prepareMap', 'readMapDataFromFile'] as $method) {
        expect(fn() => $manager->$method('test/physical'))->toThrow(InvalidArgumentException::class, 'Map occupancy')
            ->and($manager->tileMap)->toBe([['old']])
            ->and($manager->layers)->toBe($layers)
            ->and($manager->graphics)->toBeNull()
            ->and($manager->mapWidth)->toBe(1)
            ->and($manager->mapHeight)->toBe(1)
            ->and($manager->getCollision(0, 0))->toBe(CollisionType::ENCOUNTER)
            ->and($camera->worldSpace)->toBe([['old']])
            ->and($scene->party->location)->toBe($location)
            ->and($npcManager->npcs)->toBe([$resident])
            ->and($scene->musicCalls)->toBe(0)
            ->and($manager->dictionaryCalls)->toBe(0);
    }
})->with([
    'null' => [null],
    'scalar' => ['invalid'],
    'height' => [[[CollisionType::NONE, CollisionType::NONE]]],
    'width' => [[[CollisionType::NONE], [CollisionType::NONE, CollisionType::NONE]]],
    'type' => [[[CollisionType::NONE, CollisionType::NONE], [0, CollisionType::NONE]]],
    'pass-through' => [[[CollisionType::NONE, CollisionType::NONE], [CollisionType::NONE, CollisionType::PASS_THROUGH]]],
])->with([false, true]);

it('checks explicit occupancy against freshly reloaded ragged dimensions rather than the active map', function (bool $layered) {
    [$root, $paths, $grid, $manager, $scene, $camera] = createOccupancyLoadingFixture($layered, "##\n#");
    $rows = [[CollisionType::NONE, CollisionType::COUNTER], [CollisionType::SOLID]];
    file_put_contents($paths['data'], '<?php return ' . var_export(['occupancy' => $rows], true) . ';');
    expect($manager->prepareMap('test/physical')->collisions)->toBe([[0, 10], [1]]);
    file_put_contents($grid, MapGridSource::buildSource("##\n##", 'MAP'));
    file_put_contents($paths['event'], MapGridSource::buildSource("  \n  ", 'EVENT'));
    expect(fn() => $manager->prepareMap('test/physical'))->toThrow(InvalidArgumentException::class, 'row 1 must be 2 tiles wide')
        ->and($camera->worldSpace)->toBe([['old']]);
})->with([false, true]);

it('keeps real graphical layer sheet and offset changes independent of resolved physical cells', function (bool $explicit) {
    [$root, $paths, $grid, $manager] = createOccupancyLoadingFixture(true);
    mkdir($root . '/assets/Data/Tilesets', 0700, true);
    writeTestPng($root . '/assets/Graphics/Tilesets/first.png', 32, 32);
    writeTestPng($root . '/assets/Graphics/Tilesets/second.png', 64, 64);
    $tilesetPath = $root . '/assets/Data/Tilesets/synthetic.php';
    file_put_contents($tilesetPath, "<?php return ['name' => 'Synthetic', 'sheets' => ['B' => 'Graphics/Tilesets/first.png']];");
    $graphicsDirectory = dirname($paths['data']) . '/graphics';
    mkdir($graphicsDirectory);
    $graphicsPath = $graphicsDirectory . '/01.floor.tiles.php';
    file_put_contents($graphicsPath, MapGridSource::buildSource("1 2\n3 4", 'TILES'));
    $data = ['tileset' => 'synthetic', 'tileLayers' => ['floor' => ['offset' => [0, 0], 'movesWith' => 'terrain']]];
    if ($explicit) {
        $data['occupancy'] = [[CollisionType::NONE, CollisionType::COUNTER], [CollisionType::SAVE_POINT, CollisionType::ENCOUNTER]];
    }
    file_put_contents($paths['data'], '<?php return ' . var_export($data, true) . ';');
    $workingDirectory = getcwd();
    chdir($root);
    try {
        $before = $manager->prepareMap('test/physical');
        expect($before->graphics)->not->toBeNull()
            ->and($before->graphics->tileset->getUsableSheets($root . '/assets')['tileSize'])->toBe(2);
        file_put_contents($graphicsPath, MapGridSource::buildSource("4 0\n0 1", 'TILES'));
        file_put_contents($tilesetPath, "<?php return ['name' => 'Replacement', 'sheets' => ['B' => 'Graphics/Tilesets/second.png'], 'above' => [1]];");
        $data['tileLayers']['floor']['offset'] = [0.5, -0.5];
        file_put_contents($paths['data'], '<?php return ' . var_export($data, true) . ';');
        $after = $manager->prepareMap('test/physical');
        expect($after->tiles)->toBe($before->tiles)
            ->and($after->collisions)->toBe($before->collisions)
            ->and($after->graphics->layers[0]->readTiles())->toBe([[4, 0], [0, 1]])
            ->and($after->graphics->offsets)->toBe(['floor' => [0.5, -0.5]])
            ->and($after->graphics->tileset->getUsableSheets($root . '/assets')['tileSize'])->toBe(4)
            ->and($after->graphics->tileset->isAbove(1))->toBeTrue();
        if ($explicit) {
            file_put_contents($grid, MapGridSource::buildSource(".?\n~;", 'MAP'));
            $terminalEdit = $manager->prepareMap('test/physical');
            expect($terminalEdit->tiles)->not->toBe($after->tiles)
                ->and($terminalEdit->collisions)->toBe($after->collisions)
                ->and($terminalEdit->graphics->layers[0]->readTiles())->toBe($after->graphics->layers[0]->readTiles())
                ->and($terminalEdit->graphics->offsets)->toBe($after->graphics->offsets);
        }
    } finally {
        chdir($workingDirectory);
    }
})->with([false, true]);

it('loads a map painted in terrain kinds with its kinds\' passage and encounter terrain, and leaves other maps alone', function () {
    [$root, $paths, $grid, $manager, $scene, $camera, $player] = createOccupancyLoadingFixture(true, ".^\n~.");
    mkdir($root . '/assets/Data/Tilesets', 0700, true);
    file_put_contents($root . '/assets/Data/Tilesets/kinds.php', '<?php return ' . var_export(['name' => 'Kinds',
        'sheets' => ['A1' => 'Graphics/A1.png', 'A2' => 'Graphics/A2.png'], 'kinds' => [
            'meadow' => ['name' => 'Meadow', 'layer' => 'terrain', 'glyph' => '<fg=green>.</>', 'art' => ['layer' => 'ground', 'tile' => 2816],
                'passage' => 'walk', 'encounters' => 'meadow', 'atlas' => 'ground'],
            'peaks' => ['name' => 'Peaks', 'layer' => 'terrain', 'glyph' => '<fg=white>^</>', 'art' => ['awaiting' => true], 'passage' => 'solid', 'atlas' => 'highland'],
        ]], true) . ';');
    // The game's dictionary says otherwise for both glyphs; the kinds decide them on the map that opts in.
    $manager->dictionary = ['.' => CollisionType::SOLID, '^' => CollisionType::NONE, '~' => CollisionType::SOLID];
    $workingDirectory = getcwd();
    chdir($root);
    try {
        file_put_contents($paths['data'], '<?php return ' . var_export(['tileset' => 'kinds'], true) . ';');
        expect($manager->prepareMap('test/physical')->collisions)->toBe([[1, 0], [1, 1]]);

        file_put_contents($paths['data'], '<?php return ' . var_export(['tileset' => 'kinds', 'terrainKinds' => true], true) . ';');
        $prepared = $manager->prepareMap('test/physical');
        expect($prepared->collisions)->toBe([[7, 1], [1, 7]])
            ->and($prepared->encounterTerrain?->getTerrainAt(0, 0))->toBe('meadow')
            ->and($prepared->encounterTerrain?->getTerrainAt(1, 0))->toBeNull()
            ->and($prepared->overview?->terrain?->getRole(1, 0))->toBe('highland');

        file_put_contents($paths['data'], '<?php return ' . var_export(['tileset' => 'missing', 'terrainKinds' => true], true) . ';');
        expect(fn() => $manager->prepareMap('test/physical'))
            ->toThrow(InvalidArgumentException::class, 'tileset missing cannot be read, so its passage is unknown');
    } finally {
        chdir($workingDirectory);
    }
});


it('resolves saved named NPC destinations before installation and refuses blocked or overlapping current destinations', function () {
    [$root, $paths, $grid, $manager, $scene] = createOccupancyLoadingFixture(text: "....\n....\n....");
    new ReflectionProperty(GameScene::class, 'npcManager')->setValue($scene, new \Ichiloto\Engine\Field\NpcManager($scene));
    $entry = ['id' => 'arriving', 'name' => 'Arriving', 'x' => 0, 'y' => 0,
        'placements' => ['meeting' => ['x' => 3, 'y' => 1]]];
    $data = ['id' => 'test/physical', 'npcs' => [$entry]];
    file_put_contents($paths['data'], '<?php return ' . var_export($data, true) . ';');
    $scene->gameState->setVariable(\Ichiloto\Engine\Field\NpcPlacementSet::getStateKey('test/physical', 'arriving'), 'meeting');
    $prepared = $manager->prepareMap('test/physical');
    expect((int)$prepared->npcs[0]->position->x)->toBe(3)
        ->and((int)$prepared->npcs[0]->position->y)->toBe(1)->and($scene->npcManager->npcs)->toBe([]);
    $data['npcs'][] = ['id' => 'other', 'name' => 'Other', 'x' => 3, 'y' => 1];
    file_put_contents($paths['data'], '<?php return ' . var_export($data, true) . ';');
    expect(fn() => $manager->prepareMap('test/physical'))->toThrow(InvalidArgumentException::class, 'overlaps');
    $data['npcs'] = [$entry];
    file_put_contents($paths['data'], '<?php return ' . var_export($data, true) . ';');
    file_put_contents($grid, \Ichiloto\Engine\Field\MapGridSource::buildSource("....\n...#\n....", 'MAP'));
    expect(fn() => $manager->prepareMap('test/physical'))->toThrow(InvalidArgumentException::class, 'clear physical floor')
        ->and($scene->npcManager->npcs)->toBe([]);
});
