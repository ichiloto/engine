<?php

use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapLayerSource;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;

final class LayeredMapManagerProbe extends MapManager
{
    public function readSplitMap(array $paths): array
    {
        return $this->readSplitMapDataFromFiles($paths);
    }

    public function unloadGeometry(): void
    {
        $this->clearMapGeometry();
    }
}

beforeEach(function () {
    $this->directory = sys_get_temp_dir() . '/ichiloto-layers-' . bin2hex(random_bytes(8));
    mkdir($this->directory . '/layers', recursive: true);
});

afterEach(function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->directory);
});

function writeLayerGrid(string $directory, string $filename, string $text): void
{
    file_put_contents($directory . '/' . $filename, MapGridSource::buildSource($text, 'MAP'));
}

it('discovers ordered gameplay and decoration sources and composes styled occupied cells only', function () {
    writeLayerGrid($this->directory, 'layers/12.fixtures.map.php', "  \e[33mi\e[0m   ");
    writeLayerGrid($this->directory, 'layers/01.terrain.map.php', ';;;;;;');
    writeLayerGrid($this->directory, 'layers/03.floor.deco.php', 'rrrrrr');
    writeLayerGrid($this->directory, 'layers/07.buildings.map.php', '##  ##');
    $set = MapLayerSource::loadFromDirectory($this->directory, 'town');
    $grid = $set->getComposedGrid();
    expect(array_column($set->layers, 'name'))->toBe(['terrain', 'floor', 'buildings', 'fixtures'])
        ->and($set->legacy)->toBeFalse()
        ->and(array_map(TerminalText::stripAnsi(...), $grid[0]))->toBe(['##', 'i ', '##'])
        ->and($grid[0][1])->toStartWith("\e[33mi");
});

it('retains legacy ragged grids when there is no layers directory', function () {
    rmdir($this->directory . '/layers');
    writeLayerGrid($this->directory, basename($this->directory) . '.map.php', "xx\nxxxx");
    $set = MapLayerSource::loadFromDirectory($this->directory);
    expect($set->legacy)->toBeTrue()->and($set->getComposedGrid())->toBe([['xx'], ['xx', 'xx']]);
});

it('refuses malformed layer stacks rather than silently loading a legacy grid', function (array $files, string $reason) {
    foreach ($files as $name => $text) {
        writeLayerGrid($this->directory, 'layers/' . $name, $text);
    }
    writeLayerGrid($this->directory, basename($this->directory) . '.map.php', 'valid ');
    expect(fn() => MapLayerSource::loadFromDirectory($this->directory, 'town'))
        ->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'empty' => [[], 'gameplay layer'],
    'decoration only' => [['01.floor.deco.php' => 'xx'], 'gameplay layer'],
    'filename' => [['1.floor.map.php' => 'xx'], 'NN.name.map.php'],
    'duplicate order' => [['01.floor.map.php' => 'xx', '01.wall.map.php' => 'xx'], 'duplicate'],
    'duplicate name' => [['01.floor.map.php' => 'xx', '02.floor.deco.php' => 'xx'], 'duplicate'],
    'height' => [['01.floor.map.php' => "xx\nxx", '02.wall.map.php' => 'xx'], 'must have 2 rows'],
    'width' => [['01.floor.map.php' => 'xxxx', '02.wall.map.php' => 'xx'], 'must be 2 cells wide'],
    'half cell' => [['01.floor.map.php' => 'xxx'], 'ends halfway through a cell'],
]);

it('preserves ragged map geometry when every layer and event row has matching dimensions', function () {
    writeLayerGrid($this->directory, 'layers/01.terrain.map.php', "xxxx\nxx");
    writeLayerGrid($this->directory, 'layers/02.fixtures.map.php', "  ii\n  ");
    $set = MapLayerSource::loadFromDirectory($this->directory, 'town');
    $set->assertMatchingGrid(MapLayer::parseGrid("    \n  "), 'Event map');
    expect($set->getComposedGrid())->toBe([['xx', 'ii'], ['xx']]);
});

it('refuses a bad layer before executing map data and preserves the active camera', function () {
    $paths = ['id' => 'town', 'data' => $this->directory . '/town.data.php',
        'map' => $this->directory . '/town.map.php', 'event' => $this->directory . '/town.event.php'];
    file_put_contents($paths['data'], '<?php throw new RuntimeException("data was executed");');
    writeLayerGrid($this->directory, 'layers/01.floor.map.php', 'xx');
    writeLayerGrid($this->directory, 'town.event.php', '  ');
    file_put_contents($this->directory . '/layers/02.wall.map.php', "<?php\nreturn explode('x', 'x');");
    $manager = new ReflectionClass(LayeredMapManagerProbe::class)->newInstanceWithoutConstructor();
    $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
    $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: [['old']]);
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
    new ReflectionProperty(MapManager::class, 'gameScene')->setValue($manager, $scene);
    try {
        $manager->readSplitMap($paths);
        throw new RuntimeException('Bad layer accepted.');
    } catch (InvalidArgumentException $error) {
        expect($error->getMessage())->toContain('town/layers/02.wall.map.php', 'T_STRING', 'line 2')
            ->not->toContain($this->directory);
    }
    expect($camera->worldSpace)->toBe([['old']])->and($manager->layers)->toBeNull();
});

it('loads the composed grid into the camera and validates event dimensions before data evaluation', function () {
    $paths = ['id' => 'town', 'data' => $this->directory . '/town.data.php',
        'map' => $this->directory . '/town.map.php', 'event' => $this->directory . '/town.event.php'];
    writeLayerGrid($this->directory, 'layers/01.floor.map.php', ';;;;');
    writeLayerGrid($this->directory, 'layers/02.wall.map.php', '  ##');
    writeLayerGrid($this->directory, 'town.event.php', '    ');
    file_put_contents($paths['data'], '<?php return ["name" => "Town"];');
    $manager = new ReflectionClass(LayeredMapManagerProbe::class)->newInstanceWithoutConstructor();
    $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
    $camera = new Camera(makeCameraTestScene(), 8, 4);
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
    new ReflectionProperty(MapManager::class, 'gameScene')->setValue($manager, $scene);
    $manager->readSplitMap($paths);
    expect($camera->worldSpace)->toBe([[';;', '##']])->and($manager->layers?->layers)->toHaveCount(2);
    writeLayerGrid($this->directory, 'town.event.php', '  ');
    file_put_contents($paths['data'], '<?php throw new RuntimeException("data was executed");');
    expect(fn() => $manager->readSplitMap($paths))->toThrow(InvalidArgumentException::class, 'town/town.event.php row 0 must be 2 cells wide');
    expect($camera->worldSpace)->toBe([[';;', '##']]);
});

it('clears layered and legacy geometry together when a preview unloads', function (bool $layered) {
    $paths = ['id' => 'town', 'data' => $this->directory . '/town.data.php',
        'map' => $this->directory . '/town.map.php', 'event' => $this->directory . '/town.event.php'];
    if ($layered) {
        writeLayerGrid($this->directory, 'layers/01.terrain.map.php', 'xxxx');
    } else {
        rmdir($this->directory . '/layers');
        writeLayerGrid($this->directory, 'town.map.php', 'xxxx');
    }
    writeLayerGrid($this->directory, 'town.event.php', '    ');
    file_put_contents($paths['data'], '<?php return ["name" => "Town"];');
    $manager = new ReflectionClass(LayeredMapManagerProbe::class)->newInstanceWithoutConstructor();
    $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
    $camera = new Camera(makeCameraTestScene(), 8, 4);
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
    new ReflectionProperty(MapManager::class, 'gameScene')->setValue($manager, $scene);
    $manager->readSplitMap($paths);
    $collision = new ReflectionProperty(MapManager::class, 'collisionMap');
    $collision->setValue($manager, [[0, 0]]);
    expect($manager->layers?->legacy)->toBe(!$layered)->and($camera->worldSpace)->toBe([['xx', 'xx']]);
    $manager->unloadGeometry();
    expect($manager->layers)->toBeNull()->and($manager->tileMap)->toBe([])
        ->and($collision->getValue($manager))->toBe([])->and($camera->worldSpace)->toBe([])
        ->and($manager->mapWidth)->toBe(0)->and($manager->mapHeight)->toBe(0);
})->with([true, false]);

it('warns about and ignores a retired glyph-keyed tiles2d table while loading the terminal map', function () {
    $paths = ['id' => 'town', 'data' => $this->directory . '/town.data.php',
        'map' => $this->directory . '/town.map.php', 'event' => $this->directory . '/town.event.php'];
    writeLayerGrid($this->directory, 'layers/01.floor.map.php', '####');
    writeLayerGrid($this->directory, 'town.event.php', '    ');
    $data = ['tiles2d' => ['layers' => ['floor' => ['asset' => 'parts.png', 'cells' => [
        ['column' => 9, 'row' => 0, 'source' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 32]],
    ]]]]];
    file_put_contents($paths['data'], '<?php return ' . var_export($data, true) . ';');
    $manager = new ReflectionClass(LayeredMapManagerProbe::class)->newInstanceWithoutConstructor();
    $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
    $camera = new Camera(makeCameraTestScene(), 8, 4);
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
    new ReflectionProperty(MapManager::class, 'gameScene')->setValue($manager, $scene);
    $debug = new ReflectionClass(Debug::class)->getStaticProperties();
    Debug::configure(['log_directory' => $this->directory . '/logs']);
    try {
        $manager->readSplitMap($paths);
    } finally {
        foreach ($debug as $name => $value) { new ReflectionProperty(Debug::class, $name)->setValue(null, $value); }
    }
    expect($camera->worldSpace)->toBe([['##', '##']])
        ->and(file_get_contents($this->directory . '/logs/warning.log'))
        ->toContain('town/town.data.php tiles2d is no longer read');
});

it('resolves collision from the top occupied cell with per-layer overrides and pass-through', function () {
    $layers = new MapLayerSet([
        new MapLayer('terrain', 1, false, 'terrain', ';;~~~~~~  '),
        new MapLayer('buildings', 2, false, 'buildings', 'aaxxbb??  '),
        new MapLayer('fixtures', 3, false, 'fixtures', "  \e[33mi\e[0m       "),
        new MapLayer('detail', 4, true, 'detail', 'xxxxxxxxxx'),
    ]);
    $dictionary = [';' => CollisionType::ENCOUNTER, '~' => CollisionType::SOLID, ' ' => CollisionType::NONE,
        'a' => CollisionType::PASS_THROUGH, 'b' => CollisionType::NONE, 'x' => CollisionType::SOLID,
        'buildings' => ['x' => CollisionType::NONE], 'fixtures' => ['i' => CollisionType::PASS_THROUGH]];
    expect(MapCollisionResolver::resolveLayers($layers, $dictionary))->toBe([[
        CollisionType::ENCOUNTER->value, CollisionType::NONE->value, CollisionType::NONE->value,
        CollisionType::SOLID->value, CollisionType::NONE->value,
    ]]);
});

it('never stores pass-through as a final collision type even on the base layer', function () {
    $layers = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', 'xx')]);
    expect(MapCollisionResolver::resolveLayers($layers, ['x' => CollisionType::PASS_THROUGH]))
        ->toBe([[CollisionType::SOLID->value]]);
});

it('rejects decoration collision sections and malformed layer dictionary values', function () {
    $set = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', '  '),
        new MapLayer('rugs', 2, true, 'rugs', 'xx')]);
    expect(fn() => MapCollisionResolver::resolveLayers($set, ['rugs' => ['x' => CollisionType::NONE]]))
        ->toThrow(InvalidArgumentException::class, 'Decoration layer rugs');
    expect(fn() => MapCollisionResolver::validateDictionary(['terrain' => ['x' => 'solid']]))
        ->toThrow(InvalidArgumentException::class, 'layer terrain');
});

it('keeps the existing flat collision results on legacy and split maps', function () {
    $manager = new ReflectionClass(MapManager::class)->newInstanceWithoutConstructor();
    $text = ";;~~88\n  ??  ";
    $set = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', $text)]);
    $dictionary = [';' => CollisionType::ENCOUNTER, '~' => CollisionType::SOLID,
        8 => CollisionType::SOLID, '?' => CollisionType::SAVE_POINT, ' ' => CollisionType::NONE];
    expect($manager->generateLayerCollisionMap($set, $dictionary))
        ->toBe($manager->generateCollisionMap(MapLayer::parseGrid($text), $dictionary));
});
