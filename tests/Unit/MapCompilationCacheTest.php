<?php

use Ichiloto\Engine\Field\{MapCompilationCache, MapGridSource, MapLayer, MapLayerSet, MapLayerSource, MapGraphics};
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
    MapCompilationCache::clear();
    $this->cacheRoot = sys_get_temp_dir() . '/map-compilation-' . bin2hex(random_bytes(8));
    mkdir($this->cacheRoot . '/layers', 0700, true);
});
afterEach(function () {
    MapCompilationCache::clear();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->cacheRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($this->cacheRoot);
});

it('reuses immutable grids while observing equal-length edits with preserved timestamps and refusing invalid new sources', function () {
    $path = $this->cacheRoot . '/layers/00.terrain.map.php';
    file_put_contents($path, MapGridSource::buildSource('..', 'MAP'));
    $stamp = filemtime($path);
    $first = MapLayerSource::loadFromDirectory($this->cacheRoot);
    expect(MapLayerSource::loadFromDirectory($this->cacheRoot))->toBe($first);
    $copy = $first->getComposedGrid(); $copy[0][0] = '!';
    expect($first->getComposedGrid())->toBe([['.', '.']]);
    file_put_contents($path, MapGridSource::buildSource('##', 'MAP')); touch($path, $stamp);
    $changed = MapLayerSource::loadFromDirectory($this->cacheRoot);
    expect($changed)->not->toBe($first)->and($changed->getComposedGrid())->toBe([['#', '#']]);
    file_put_contents($path, '<?php echo "unsupported";');
    expect(fn() => MapLayerSource::loadFromDirectory($this->cacheRoot))->toThrow(InvalidArgumentException::class);
    expect($first->getComposedGrid())->toBe([['.', '.']]);
});

it('reuses parsed tile grids but revalidates changed rows and map geometry', function () {
    $layers = new MapLayerSet([new MapLayer('terrain', 0, false, 'synthetic', '..')]);
    $sources = ['00.ground.tiles.php' => MapGridSource::buildSource('1 1', 'TILES')];
    $first = MapGraphics::readLayersFromSources($sources, 'synthetic', $layers);
    expect(MapGraphics::readLayersFromSources($sources, 'synthetic', $layers)[0])->toBe($first[0]);
    $sources['00.ground.tiles.php'] = MapGridSource::buildSource('2 2', 'TILES');
    $changed = MapGraphics::readLayersFromSources($sources, 'synthetic', $layers);
    expect($changed[0]->getTileAt(0, 0))->toBe(2)->and($first[0]->getTileAt(0, 0))->toBe(1);
    $narrow = new MapLayerSet([new MapLayer('terrain', 0, false, 'synthetic', '.')]);
    expect(fn() => MapGraphics::readLayersFromSources($sources, 'synthetic', $narrow))->toThrow(InvalidArgumentException::class);
});

it('reuses world compilation while keeping current artwork bytes presentation overrides and worlds independent', function () {
    $layers = new MapLayerSet([new MapLayer('terrain', 0, false, 'synthetic', '..')]);
    $asset = 'Graphics/sheet.png'; $path = $this->cacheRoot . '/' . $asset;
    writeTestPng($path, 32, 32);
    $tileset = Tileset::fromArray('synthetic', ['name' => 'Synthetic', 'sheets' => ['B' => $asset]]);
    $graphics = MapGraphics::fromSources(['00.ground.tiles.php' => MapGridSource::buildSource('1 1', 'TILES')],
        'synthetic', 'synthetic', $layers, $this->cacheRoot, tileset: $tileset);
    $first = PresentationWorld::getFromLayers($layers, 'map', $graphics, $this->cacheRoot);
    expect(PresentationWorld::getFromLayers($layers, 'map', $graphics, $this->cacheRoot))->toBe($first);
    $expected = $first->getOperations(true, true);
    $weak = WeakReference::create($first);
    unset($first);
    expect($weak->get())->toBeNull();
    $first = PresentationWorld::getFromLayers($layers, 'map', $graphics, $this->cacheRoot);
    expect($first->getOperations(true, true))->toBe($expected);
    $stamp = filemtime($path);
    // A valid ancillary PNG chunk changes current bytes while its dimensions and timestamp stay fixed.
    $bytes = file_get_contents($path);
    $chunk = 'tEXt' . "Comment\0replacement";
    file_put_contents($path, substr($bytes, 0, -12) . pack('N', strlen($chunk) - 4) . $chunk . pack('N', crc32($chunk)) . substr($bytes, -12));
    touch($path, $stamp);
    expect(PresentationWorld::getFromLayers($layers, 'map', $graphics, $this->cacheRoot))->not->toBe($first);
    writeTestPng($path, 64, 64); touch($path, $stamp);
    $changed = PresentationWorld::getFromLayers($layers, 'map', $graphics, $this->cacheRoot);
    expect($changed)->not->toBe($first)
        ->and($changed->operations[0]['value']['tileset']['tileSize'])->toBe(4)
        ->and($first->operations[0]['value']['tileset']['tileSize'])->toBe(2);
    $override = PresentationWorld::getFromLayers($layers, 'map', $graphics, $this->cacheRoot, terminalCells: [0 => [0 => 'X']]);
    expect($override)->not->toBe($changed)->and($override->operations[1]['rows'][0]['cells'][0]['glyph'])->toBe('X');
    unlink($path);
    $missing = PresentationWorld::getFromLayers($layers, 'map', $graphics, $this->cacheRoot);
    expect($missing)->not->toBe($changed)->and($missing->operations[0]['value'])->not->toHaveKey('tileset');
});

it('evicts compiled values within the shared byte and entry ceilings without changing held immutable results', function () {
    $layers = new MapLayerSet([new MapLayer('terrain', 0, false, 'synthetic', '.')]);
    MapCompilationCache::store('new', $layers, 2);
    MapCompilationCache::store('oversized', $layers, MapCompilationCache::MAX_EXPANDED_BYTES + 1);
    expect(MapCompilationCache::find('oversized'))->toBeNull()->and(MapCompilationCache::find('new'))->toBe($layers);
    $held = WeakReference::create($layers);
    unset($layers);
    expect($held->get())->toBeNull();
    $layers = MapCompilationCache::find('new');
    expect($layers)->toBeInstanceOf(MapLayerSet::class)->and($layers->getComposedGrid())->toBe([['.']]);
    MapCompilationCache::clear();
    foreach (range(0, MapCompilationCache::MAX_ENTRIES) as $index) { MapCompilationCache::store('entry:' . $index, $layers, 1); }
    expect(MapCompilationCache::find('entry:0'))->toBeNull()->and($layers->getComposedGrid())->toBe([['.']]);
});


it('charges actual compressed bytes and evicts the least recently used snapshot before exceeding the byte ceiling', function () {
    $make = static function (string $name, int $bytes): MapLayerSet {
        // A synthetic opaque source path exercises incompressible retained metadata without allocating a giant grid.
        $path = base64_encode(random_bytes($bytes));
        return new MapLayerSet([new MapLayer($name, 0, false, $path, '.')]);
    };
    $first = $make('first', 280000);
    $second = $make('second', 280000);
    MapCompilationCache::store('first', $first, 600000);
    expect(MapCompilationCache::find('first'))->toBe($first);
    MapCompilationCache::store('second', $second, 600000);
    expect(MapCompilationCache::find('first'))->toBeNull()->and(MapCompilationCache::find('second'))->toBe($second);
    $oversized = $make('oversized', MapCompilationCache::MAX_BYTES + 1000);
    MapCompilationCache::store('oversized', $oversized, 2 * MapCompilationCache::MAX_BYTES);
    expect(MapCompilationCache::find('oversized'))->toBeNull()->and(MapCompilationCache::find('second'))->toBe($second);
});
