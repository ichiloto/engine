<?php

use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapLayerSource;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;

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
    writeLayerGrid($this->directory, 'layers/12.fixtures.map.php', " \e[33mi\e[0m ");
    writeLayerGrid($this->directory, 'layers/01.terrain.map.php', ';;;');
    writeLayerGrid($this->directory, 'layers/03.floor.deco.php', 'rrr');
    writeLayerGrid($this->directory, 'layers/07.buildings.map.php', '# #');
    $set = MapLayerSource::loadFromDirectory($this->directory, 'town');
    expect(array_column($set->layers, 'name'))->toBe(['terrain', 'floor', 'buildings', 'fixtures'])
        ->and($set->legacy)->toBeFalse()
        ->and($set->getComposedGrid())->toBe([['#', "\e[33mi\e[0m", '#']]);
});

it('retains legacy ragged grids when there is no layers directory', function () {
    rmdir($this->directory . '/layers');
    writeLayerGrid($this->directory, basename($this->directory) . '.map.php', "x\nxx");
    $set = MapLayerSource::loadFromDirectory($this->directory);
    expect($set->legacy)->toBeTrue()->and($set->getComposedGrid())->toBe([['x'], ['x', 'x']]);
});

it('refuses malformed layer stacks rather than silently loading a legacy grid', function (array $files, string $reason) {
    foreach ($files as $name => $text) {
        writeLayerGrid($this->directory, 'layers/' . $name, $text);
    }
    writeLayerGrid($this->directory, basename($this->directory) . '.map.php', 'valid');
    expect(fn() => MapLayerSource::loadFromDirectory($this->directory, 'town'))
        ->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'empty' => [[], 'gameplay layer'],
    'decoration only' => [['01.floor.deco.php' => 'x'], 'gameplay layer'],
    'filename' => [['1.floor.map.php' => 'x'], 'NN.name.map.php'],
    'duplicate order' => [['01.floor.map.php' => 'x', '01.wall.map.php' => 'x'], 'duplicate'],
    'duplicate name' => [['01.floor.map.php' => 'x', '02.floor.deco.php' => 'x'], 'duplicate'],
    'height' => [['01.floor.map.php' => "xx\nxx", '02.wall.map.php' => 'xx'], 'must have 2 rows'],
    'width' => [['01.floor.map.php' => 'xx', '02.wall.map.php' => 'x'], 'must be 2 tiles wide'],
]);

it('preserves ragged map geometry when every layer and event row has matching dimensions', function () {
    writeLayerGrid($this->directory, 'layers/01.terrain.map.php', "xx\nx");
    writeLayerGrid($this->directory, 'layers/02.fixtures.map.php', " i\n ");
    $set = MapLayerSource::loadFromDirectory($this->directory, 'town');
    $set->assertMatchingGrid(MapLayer::parseGrid("  \n "), 'Event map');
    expect($set->getComposedGrid())->toBe([['x', 'i'], ['x']]);
});

it('refuses a bad layer before executing map data and preserves the active camera', function () {
    $paths = ['id' => 'town', 'data' => $this->directory . '/town.data.php',
        'map' => $this->directory . '/town.map.php', 'event' => $this->directory . '/town.event.php'];
    file_put_contents($paths['data'], '<?php throw new RuntimeException("data was executed");');
    writeLayerGrid($this->directory, 'layers/01.floor.map.php', 'xx');
    writeLayerGrid($this->directory, 'town.event.php', '  ');
    file_put_contents($this->directory . '/layers/02.wall.map.php', "<?php\nreturn explode('x', 'x');");
    $manager = new ReflectionClass(SplitMapManagerProbe::class)->newInstanceWithoutConstructor();
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
    writeLayerGrid($this->directory, 'layers/01.floor.map.php', ';;');
    writeLayerGrid($this->directory, 'layers/02.wall.map.php', ' #');
    writeLayerGrid($this->directory, 'town.event.php', '  ');
    file_put_contents($paths['data'], '<?php return ["name" => "Town"];');
    $manager = new ReflectionClass(SplitMapManagerProbe::class)->newInstanceWithoutConstructor();
    $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
    $camera = new Camera(makeCameraTestScene(), 8, 4);
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
    new ReflectionProperty(MapManager::class, 'gameScene')->setValue($manager, $scene);
    $manager->readSplitMap($paths);
    expect($camera->worldSpace)->toBe([[';', '#']])->and($manager->layers?->layers)->toHaveCount(2);
    writeLayerGrid($this->directory, 'town.event.php', ' ');
    file_put_contents($paths['data'], '<?php throw new RuntimeException("data was executed");');
    expect(fn() => $manager->readSplitMap($paths))->toThrow(InvalidArgumentException::class, 'town/town.event.php row 0 must be 2 tiles wide');
    expect($camera->worldSpace)->toBe([[';', '#']]);
});
