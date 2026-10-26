<?php

declare(strict_types=1);

use Ichiloto\Engine\Field\MapGridSource;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

function createRetainedBenchmarkProject(string $project, ?int $tileSize, int $width, int $height): void
{
    $assets = $project . '/assets';
    $map = $assets . '/Maps/fixture';
    mkdir($map . '/layers', 0777, true);
    $rows = implode("\n", array_fill(0, $height, str_repeat('.', $width)));
    file_put_contents($map . '/layers/00.terrain.map.php', MapGridSource::buildSource($rows, 'BENCHMARK_MAP'));
    $data = ['name' => 'Synthetic benchmark'];
    if ($tileSize !== null) {
        mkdir($map . '/graphics');
        mkdir($assets . '/Data/Tilesets', 0777, true);
        writeTestPng($assets . '/Graphics/Tilesets/synthetic.png', 16 * $tileSize, 16 * $tileSize);
        file_put_contents($assets . '/Data/Tilesets/synthetic.php', "<?php\nreturn " . var_export([
            'name' => 'Synthetic tiles', 'sheets' => ['B' => 'Graphics/Tilesets/synthetic.png'],
        ], true) . ";\n");
        $tiles = implode("\n", array_fill(0, $height, implode(' ', array_fill(0, $width, '1'))));
        file_put_contents($map . '/graphics/00.floor.tiles.php', MapGridSource::buildSource($tiles, 'BENCHMARK_TILES'));
        $data['tileset'] = 'synthetic';
        $data['tileLayers'] = ['floor' => ['offset' => [0.5, -0.5], 'movesWith' => 'terrain']];
    } else {
        // Retired glyph-keyed data must not resurrect the removed stateless path.
        $data['tiles2d'] = ['.' => ['asset' => 'retired.png']];
    }
    file_put_contents($map . '/fixture.data.php', "<?php\nreturn " . var_export($data, true) . ";\n");
    // Exercise Runtime's real scene UI lookup even though there are no active modals.
    mkdir($assets . '/Data/Presentation', 0777, true);
    file_put_contents($assets . '/Data/Presentation/dialogue.php', "<?php\nreturn [];\n");
}

/** Synthetic project snapshots check read-only behavior, not production artwork identity. */
function getRetainedBenchmarkProjectHashes(string $project): array
{
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) { $hashes[substr($file->getPathname(), strlen($project) + 1)] = hash_file('sha256', $file->getPathname()); }
    }
    ksort($hashes);
    return $hashes;
}

/** A wall-clock deadline complements PHP's CPU and memory limits; no renderer is started. */
function runRetainedBenchmarkProcess(string $project, array $options = []): array
{
    $engine = dirname(__DIR__, 2);
    $directory = dirname($project);
    $arguments = [PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'max_execution_time=10',
        $engine . '/tools/benchmark-retained-presentation.php', '--project=' . $project,
        '--engine=' . $engine, '--dependencies=' . $engine . '/vendor', '--map=fixture'];
    foreach (['columns' => 51, 'rows' => 13, 'start-x' => 2, 'start-y' => 2,
        'warmup' => 1, 'iterations' => 1, ...$options] as $key => $value) {
        $arguments[] = '--' . $key . '=' . $value;
    }
    $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'],
        1 => ['file', $directory . '/stdout', 'w'], 2 => ['file', $directory . '/stderr', 'w']], $pipes, $directory);
    if (!is_resource($process)) { throw new RuntimeException('Could not start benchmark subprocess.'); }
    try {
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            if (hrtime(true) >= $deadline) { throw new RuntimeException('Benchmark exceeded its 10-second wall-clock budget.'); }
            usleep(10_000);
        } while (true);
    } finally {
        if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
        proc_close($process);
    }
    return ['exit' => $status['exitcode'], 'stdout' => file_get_contents($directory . '/stdout'),
        'stderr' => file_get_contents($directory . '/stderr')];
}

beforeEach(function () {
    $this->benchmarkDirectory = sys_get_temp_dir() . '/ichiloto-retained-benchmark-' . bin2hex(random_bytes(6));
    mkdir($this->benchmarkDirectory);
});

afterEach(function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->benchmarkDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($this->benchmarkDirectory);
});

it('benchmarks current retained worlds with eight viewport-only scrolls and an unchanged no-op',
    function (?int $tileSize, int $width, int $height, bool $staged) {
        $project = $this->benchmarkDirectory . '/project';
        createRetainedBenchmarkProject($project, $tileSize, $width, $height);
        $before = getRetainedBenchmarkProjectHashes($project);
        $process = runRetainedBenchmarkProcess($project);
        expect($process['exit'])->toBe(0, $process['stderr'])
            ->and($process['stderr'])->toBe('')->and($process['stdout'])->not->toContain("\033")
            ->and(getRetainedBenchmarkProjectHashes($project))->toBe($before);
        $result = json_decode($process['stdout'], true, flags: JSON_THROW_ON_ERROR);
        expect($result['mode'])->toBe('retained')->and($result['traceEnabled'])->toBeFalse()
            ->and($result['grid'])->toBe([51, 13])->and($result['cellSize'])->toBe([10, 20])
            ->and($result['fieldGrid'])->toBe([10, 5])->and($result['fieldCellSize'])->toBe([48, 48])
            ->and($result['fieldZoom'])->toEqual(1.0)->and($result['worldSize'])->toBe([$width, $height])
            ->and($result['warmupCycles'])->toBe(1)->and($result['measuredCycles'])->toBe(1)
            ->and($result['initial']['worldUploads'])->toBe(1)
            ->and($result['initial']['worldCells'])->toBe($width * $height)
            ->and($result['initial']['tileCells'])->toBe($tileSize === null ? 0 : $width * $height)
            ->and($result['initial']['presentedFrames'])->toBe(1)
            ->and($result['initial']['world']['id'])->toBe('map')
            ->and($result['initial']['world']['size'])->toBe([$width, $height])
            ->and($result['initial']['world']['cellSize'])->toBe([48, 48])
            ->and($result['initial']['viewport']['worldOrigin'])->toBe(['column' => 2, 'row' => 2])
            ->and($result['initial']['bytes'])->toBeGreaterThan(0)
            ->and($result['initial']['operations'])->toBeGreaterThan(0)
            ->and($result['unchangedPackets'])->toBe(0)->and($result['samples'])->toHaveCount(8);
        if ($staged) {
            expect($result['initial']['runtimeCalls'])->toBeGreaterThan(1)
                ->and($result['initial']['packets'])->toBeGreaterThan(1);
        }
        $world = $result['initial']['world'];
        expect($world['tileSize'])->toBe($tileSize)
            ->and($world['tileCatalogSize'])->toBe($tileSize === null ? 0 : 1)
            ->and($world['tileSheets'])->toBe($tileSize === null ? [] : ['Graphics/Tilesets/synthetic.png']);
        if ($tileSize !== null) {
            $tileLayer = array_column($world['layers'], null, 'id')['tiles:floor'];
            expect($tileLayer['coversLayerId'])->toBe('map:terrain');
        }
        $expectedPositions = [['north', 2, 1], ['east', 3, 1], ['south', 3, 2], ['west', 2, 2],
            ['north-west', 0, 0], ['north-east', $width - 10, 0],
            ['south-east', $width - 10, $height - 5], ['south-west', 0, $height - 5]];
        expect($result['positions'])->toBe($expectedPositions);
        $viewports = [$result['initial']['viewport']];
        foreach ($result['samples'] as $index => $sample) {
            [$label, $x, $y] = $expectedPositions[$index];
            expect($sample['cycle'])->toBe(0)->and($sample['position'])->toBe($label)
                ->and($sample['packets'])->toBe(1)->and($sample['operations'])->toBe(0)
                ->and($sample['worldUploads'])->toBe(0)->and($sample['tileCells'])->toBe(0)
                ->and($sample['textRuns'])->toBe(0)->and($sample['bytes'])->toBeGreaterThan(0)
                ->and($sample['viewport']['worldOrigin'])->toBe(['column' => $x, 'row' => $y]);
            $viewports[] = $sample['viewport'];
        }
        foreach ($viewports as $viewport) {
            expect($viewport['scale'])->toEqual(1.0)->and($viewport['worldId'])->toBe('map')
                ->and($viewport['origin'])->toBe(['x' => 15, 'y' => 10])
                ->and($viewport['clipRect'])->toBe(['x' => 0, 'y' => 0, 'width' => 510, 'height' => 260])
                ->and($viewport['spriteIds'])->toBe([]);
        }
        expect($result['summary']['packets']['count'])->toBe(8)
            ->and($result['summary']['packets']['min'])->toBe(1)->and($result['summary']['packets']['max'])->toBe(1)
            ->and($result['summary']['operations']['max'])->toBe(0)
            ->and($result['sources'])->toHaveKeys(['src/Field/MapGraphics.php', 'src/Rendering/FieldViewport.php',
                'src/Scenes/AbstractScene.php', 'src/UI/UIManager.php']);
    })->with([
        'glyph-only retained map' => [null, 32, 16, false],
        'replaceable 16-pixel tiles' => [16, 32, 16, false],
        'staged 48-pixel tiles' => [48, 160, 100, true],
    ]);

it('refuses a benchmark whose requested scroll positions are not distinct', function () {
    $project = $this->benchmarkDirectory . '/project';
    createRetainedBenchmarkProject($project, null, 32, 16);
    $before = getRetainedBenchmarkProjectHashes($project);
    $process = runRetainedBenchmarkProcess($project, ['start-x' => 0, 'start-y' => 1]);
    expect($process['exit'])->not->toBe(0)
        ->and($process['stderr'])->toContain('eight distinct scroll positions')
        ->and(getRetainedBenchmarkProjectHashes($project))->toBe($before);
});
