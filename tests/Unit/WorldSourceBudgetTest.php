<?php

use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\TerminalCapabilities;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Tiles\GraphicalTileDefinition;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

beforeEach(function () {
    $this->states = [];
    foreach ([ConfigStore::class, TerminalCapabilities::class] as $class) {
        $this->states[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['graphics' => ['sprites' => ['allow_composite_emoji' => true]]]));
});

afterEach(function () {
    foreach ($this->states as $class => $state) {
        foreach ($state as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
    }
});

/** Replay the native World::estimated_bytes / row_bytes charge from emitted data. */
function getNativeWorldSourceCharge(PresentationWorld $world): int
{
    $bytes = 0;
    foreach ($world->operations as $operation) {
        if ($operation['op'] === 'put') {
            $bytes += count($operation['value']['layers']) * 8192;
        } elseif ($operation['op'] === 'worldRows') {
            foreach ($operation['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    $bytes += 64 + strlen($cell['glyph']) + strlen($cell['ownerLayerId']);
                }
            }
        } elseif ($operation['op'] === 'worldTiles') {
            foreach ($operation['rows'] as $row) { $bytes += count($row['cells']) * 16; }
        }
    }
    return $bytes;
}

it('matches native source coefficients and charges rendered UTF-8 bytes and actual ragged-row owners', function () {
    $layers = new MapLayerSet([
        new MapLayer('terrain', 0, false, 'base', "\e[31m\u{e9}\e[0m \u{754c}\u{1f600}a\ne\u{301}."),
        new MapLayer('fixtures_long', 1, false, 'fixtures', " Z   \n  "),
        new MapLayer('ornaments', 2, true, 'ornaments', "     \n  "),
    ]);
    $world = PresentationWorld::getFromLayers($layers, []);
    expect(PresentationWorld::MAX_SOURCE_BYTES)->toBe(67108864)
        ->and(PresentationWorld::LAYER_SOURCE_BYTES)->toBe(8192)
        ->and(PresentationWorld::CELL_SOURCE_BYTES)->toBe(64)
        ->and(PresentationWorld::TILE_SOURCE_BYTES)->toBe(16)
        ->and(array_column($world->operations[1]['rows'][0]['cells'], 'glyph'))
        ->toBe(["\u{e9}", 'Z', "\u{754c}", "\u{1f600}", 'a'])
        ->and(array_column($world->operations[2]['rows'][0]['cells'], 'glyph'))->toBe(['?', '.'])
        ->and(array_column($world->operations[1]['rows'][0]['cells'], 'ownerLayerId'))
        ->toBe(['map:terrain', 'map:fixtures_long', 'map:terrain', 'map:terrain', 'map:terrain'])
        // Three layers, seven authored cells, thirteen rendered glyph bytes, 83 owner-ID bytes.
        ->and($world->estimatedSourceBytes)->toBe(3 * 8192 + 7 * 64 + 13 + 83)
        ->and($world->estimatedSourceBytes)->toBe(getNativeWorldSourceCharge($world));
});

it('charges each emitted crop candidate including hidden base crops and explicit supplementary blanks', function () {
    $layers = new MapLayerSet([
        new MapLayer('terrain', 0, false, 'base', ". .\n.."),
        new MapLayer('fixtures', 1, false, 'fixtures', " x \n  "),
        new MapLayer('ornaments', 2, true, 'ornaments', "  *\n  "),
    ]);
    $crop = ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 16];
    $definitions = GraphicalTileDefinition::getForLayers(['asset' => 'tiles.png', 'layers' => [
        'terrain' => ['symbols' => ['.' => $crop, ' ' => $crop]],
        'fixtures' => ['symbols' => [' ' => $crop, 'x' => $crop],
            'cells' => [['column' => 0, 'row' => 0, 'source' => $crop]]],
        'ornaments' => ['symbols' => ['*' => $crop]],
    ]], $layers, 'Source charge fixture');
    GraphicalTileDefinition::validateDecoration($layers, $definitions);
    $world = PresentationWorld::getFromLayers($layers, $definitions);
    $candidates = [];
    foreach ($world->operations as $operation) {
        if ($operation['op'] !== 'worldTiles') { continue; }
        foreach ($operation['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                $candidates[] = [$operation['layerId'], $row['row'], $cell['column']];
            }
        }
    }
    expect($candidates)->toBe([
        ['map:terrain', 0, 0], ['map:terrain', 0, 1], ['map:terrain', 0, 2],
        ['map:terrain', 1, 0], ['map:terrain', 1, 1],
        ['map:fixtures', 0, 0], ['map:fixtures', 0, 1], ['map:ornaments', 0, 2],
    ])->and($world->estimatedSourceBytes)->toBe(3 * 8192 + 5 * 64 + 5 + 56 + 8 * 16)
        ->and($world->estimatedSourceBytes)->toBe(getNativeWorldSourceCharge($world));
});

it('does not charge a wide-cell override as a tile candidate on a legacy ragged map', function () {
    $layers = new MapLayerSet([new MapLayer('terrain', 0, false, 'legacy', "\u{754c} .\n.")], legacy: true);
    $crop = ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 16];
    $definitions = GraphicalTileDefinition::getForLayers(['asset' => 'tiles.png',
        'symbols' => ['.' => $crop, ' ' => $crop],
        'cells' => [['column' => 0, 'row' => 0, 'source' => $crop]],
    ], $layers, 'Legacy source charge fixture');
    $world = PresentationWorld::getFromLayers($layers, $definitions);
    expect($world->operations[3]['rows'][0]['cells'])->toBe([
        ['column' => 1, 'source' => 0], ['column' => 2, 'source' => 0],
    ])->and($world->estimatedSourceBytes)->toBe(8192 + 4 * 64 + 6 + 4 * 11 + 3 * 16)
        ->and($world->estimatedSourceBytes)->toBe(getNativeWorldSourceCharge($world));
});

it('keeps real MapManager screen deltas and logs once when source bytes alone exceed the native cap under 128 MiB', function () {
    $root = sys_get_temp_dir() . '/ichiloto-world-budget-' . bin2hex(random_bytes(8));
    mkdir($root);
    try {
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'max_execution_time=30',
            __DIR__ . '/../Support/WorldSourceBudgetProbe.php', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/stdout', 'w'],
                2 => ['file', $root . '/stderr', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        $exitCode = proc_close($process);
        $this->assertSame(0, $exitCode, file_get_contents($root . '/stderr') . file_get_contents($root . '/stdout'));
        $result = json_decode(file_get_contents($root . '/stdout'), true, flags: JSON_THROW_ON_ERROR);
        expect($result['memoryLimit'])->toBe('128M')
            ->and($result['peakBytes'])->toBeLessThan(128 * 1024 * 1024)
            ->and($result['cellCount'])->toBe(250000)->toBeLessThan(PresentationWorld::MAX_CELLS)
            ->and($result['extent'])->toBe(500)->toBeLessThan(PresentationWorld::MAX_EXTENT)
            ->and($result['layerCount'])->toBe(1)->toBeLessThan(PresentationWorld::MAX_LAYERS)
            ->and($result['ownerIdBytes'])->toBe(214)->toBeLessThanOrEqual(256)
            ->and($result['sourceBytes'])->toBe(69758192)->toBeGreaterThan(PresentationWorld::MAX_SOURCE_BYTES)
            ->and($result['initialWorldAvailable'])->toBeTrue()
            ->and($result['oversizedWorldAvailable'])->toBeFalse()
            ->and($result['retainedMode'])->toBeTrue()
            ->and($result['screenRows'])->toBe(array_fill(0, 4, '........'))
            ->and($result['deltaLayerIds'])->toContain('map:' . str_repeat('a', 210))
            ->and($result['warningCount'])->toBe(1)
            ->and($result['warningUnchanged'])->toBeTrue()
            ->and($result['recoveredWorldAvailable'])->toBeTrue();
        expect(file_get_contents($root . '/warning.log'))->toContain('source-memory budget')
            ->not->toContain('bounded layer or cell budget', 'map atlas was omitted');
    } finally {
        foreach (new DirectoryIterator($root) as $file) {
            if (!$file->isDot()) { unlink($file->getPathname()); }
        }
        rmdir($root);
    }
});
