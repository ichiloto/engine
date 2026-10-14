#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Read-only, map-only PHP presentation benchmark; never launches a renderer.
 * Run this same tool in separate processes against retained-API source revisions.
 * Example: php tools/benchmark-retained-presentation.php --project=/path/to/game
 *   --engine=/path/to/engine --dependencies=/path/to/installed/engine/vendor
 *   --map=region/map --columns=200 --rows=50 --start-x=8 --start-y=20
 *   --warmup=3 --iterations=15 --output=/tmp/new-result.json
 * --trace adds opt-in component timings; keep that separate from headline runs.
 * Dependencies are shared read-only; Engine classes AND autoload files come
 * exclusively from --engine, including when it is a detached source checkout.
 */

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapLayerSource;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererTransportState;
use Ichiloto\Engine\Rendering\Transport\Interfaces\RendererTransportInterface;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;

const BENCHMARK_CELL_WIDTH = 10;
const BENCHMARK_CELL_HEIGHT = 20;
const BENCHMARK_MAX_COLD_UPLOAD_CALLS = 10000;

$options = getopt('', ['project:', 'engine:', 'dependencies:', 'map:', 'columns:', 'rows:',
    'start-x:', 'start-y:', 'warmup:', 'iterations:', 'output:', 'trace', 'help']);
if (isset($options['help']) || !isset($options['project'], $options['map'])) {
    fwrite(STDOUT, "Required: --project=DIR --map=ID. Optional: --engine=DIR --dependencies=VENDOR\n"
        . "--columns=200 --rows=50 --start-x=8 --start-y=20 --warmup=3 --iterations=15\n"
        . "--trace --output=NEW_FILE. Reports PHP only, no native/GPU/audio or gameplay simulation.\n");
    exit(isset($options['help']) ? 0 : 2);
}
$engine = realpath($options['engine'] ?? dirname(__DIR__));
$project = realpath($options['project']);
$vendor = realpath($options['dependencies'] ?? ($engine . '/vendor'));
if ($engine === false || $project === false || $vendor === false) { throw new InvalidArgumentException('Paths must exist.'); }
$map = $options['map'];
$assets = realpath($project . '/assets');
$mapDirectory = realpath($assets . '/Maps/' . $map);
if ($assets === false || $mapDirectory === false || !str_starts_with($mapDirectory, $assets . '/Maps/')) {
    throw new InvalidArgumentException('Map must be inside the project asset Maps directory.');
}
$getInteger = static function (string $key, int $default, int $min, int $max) use ($options): int {
    $value = filter_var($options[$key] ?? $default, FILTER_VALIDATE_INT);
    if ($value === false || $value < $min || $value > $max) { throw new InvalidArgumentException("Invalid --$key."); }
    return $value;
};
$startX = $getInteger('start-x', 8, 0, 16384);
$startY = $getInteger('start-y', 20, 1, 16384);
$warmup = $getInteger('warmup', 3, 1, 1000);
$iterations = $getInteger('iterations', 15, 1, 10000);
$output = $options['output'] ?? null;
if ($output !== null && (file_exists($output) || is_link($output))) { throw new RuntimeException('Refusing to overwrite output.'); }

// Assemble Composer's existing maps without executing another checkout's Engine files.
require_once $vendor . '/composer/ClassLoader.php';
$loader = new Composer\Autoload\ClassLoader($vendor);
foreach (require $vendor . '/composer/autoload_psr4.php' as $prefix => $paths) {
    $loader->setPsr4($prefix, $prefix === 'Ichiloto\\Engine\\' ? [$engine . '/src'] : $paths);
}
foreach (require $vendor . '/composer/autoload_namespaces.php' as $prefix => $paths) { $loader->set($prefix, $paths); }
$classMap = require $vendor . '/composer/autoload_classmap.php';
$loader->addClassMap(array_filter($classMap, static fn($class) => !str_starts_with($class, 'Ichiloto\\Engine\\'), ARRAY_FILTER_USE_KEY));
$loader->setPsr4('Ichiloto\\Engine\\', [$engine . '/src']);
$loader->register();
$dependencyRoot = dirname($vendor);
foreach (require $vendor . '/composer/autoload_files.php' as $id => $file) {
    if (str_starts_with($file, $dependencyRoot . '/src/')) { $file = $engine . substr($file, strlen($dependencyRoot)); }
    if (!isset($GLOBALS['__composer_autoload_files'][$id])) {
        $GLOBALS['__composer_autoload_files'][$id] = true;
        require $file;
    }
}
chdir($project);
$columns = $getInteger('columns', 200, 2, RendererGridConfig::MAX_COLUMNS);
$rows = $getInteger('rows', 50, 2, RendererGridConfig::MAX_ROWS);
ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => $columns, 'height' => $rows]));

/** An encoded sink with immediate synthetic acknowledgements, not pipe/native latency. */
final class BenchmarkPresentationTransport implements RendererTransportInterface
{
    public bool $running = false;
    public array $events = [];
    public int $encodeNs = 0;
    public int $bytes = 0;
    public int $packets = 0;
    public int $operations = 0;
    public int $tileCells = 0;
    public int $textRuns = 0;
    public int $worldUploads = 0;
    public int $worldCells = 0;
    public int $presentedFrames = 0;
    public ?array $worldMetadata = null;
    public ?array $viewport = null;
    public function start(RendererSessionConfig $session): void
    {
        $this->running = true;
        $this->events[] = RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
            'capabilities' => $session->getNegotiableCapabilities()], JSON_THROW_ON_ERROR));
    }
    public function send(RendererMessage $message): void
    {
        $started = hrtime(true);
        $encoded = $message->encode();
        $this->encodeNs += hrtime(true) - $started;
        $this->bytes += strlen($encoded);
        $this->packets++;
        $this->operations += count($message->payload['operations'] ?? []);
        if (array_key_exists('viewport', $message->payload)) { $this->viewport = $message->payload['viewport']; }
        foreach ($message->payload['operations'] ?? [] as $operation) {
            if ($operation['op'] === 'put' && ($operation['kind'] ?? null) === 'world') {
                $this->worldUploads++;
                $value = $operation['value'];
                $this->worldMetadata = ['id' => $operation['id'], 'size' => [$value['columns'], $value['rows']],
                    'cellSize' => [$value['cellWidth'], $value['cellHeight']], 'layers' => $value['layers'],
                    'tileSize' => $value['tileset']['tileSize'] ?? null,
                    'tileSheets' => $value['tileset']['sheets'] ?? [],
                    'tileCatalogSize' => count($value['tileset']['tiles'] ?? [])];
            }
            foreach ($operation['rows'] ?? [] as $row) {
                if ($operation['op'] === 'worldRows') { $this->worldCells += count($row['cells']); }
                elseif ($operation['op'] === 'worldTiles') { $this->tileCells += count($row['cells']); }
                elseif ($operation['op'] === 'textRows') { $this->textRuns += count($row['runs']); }
            }
        }
        if (isset($message->payload['generation'])) {
            if ($message->payload['present']) { $this->presentedFrames++; }
            $this->events[] = RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'frame_ack',
                'generation' => $message->payload['generation'], 'frame' => $message->payload['frame'],
                'presented' => $message->payload['present']], JSON_THROW_ON_ERROR));
        }
    }
    public function resetCounters(): void
    {
        $this->encodeNs = $this->bytes = $this->packets = $this->operations = $this->tileCells = $this->textRuns = $this->worldUploads = $this->worldCells = $this->presentedFrames = 0;
        $this->worldMetadata = $this->viewport = null;
    }
    public function trySend(RendererMessage $message): bool { $this->send($message); return true; }
    public function getPendingWriteBytes(): int { return 0; }
    public function pollEvents(float $waitSeconds = 0.0): array { $events = $this->events; $this->events = []; return $events; }
    public function isRunning(): bool { return $this->running; }
    public function shutdown(): ?int { $this->running = false; return 0; }
    public function getState(): RendererTransportState { return $this->running ? RendererTransportState::RUNNING : RendererTransportState::STOPPED; }
    public function getExitCode(): ?int { return $this->running ? null : 0; }
    public function getDiagnostics(): string { return 'Read-only encoded benchmark sink; no native process.'; }
}

/** An inert scene owner; only the benchmark owns startup and cleanup. */
final class BenchmarkPresentationGame extends Game
{
    public function __construct() {}
    public function __destruct() {}
}

/** Real scene/UI providers without starting gameplay, persistence or audio services. */
final class BenchmarkPresentationScene extends GameScene
{
    public function __construct(MapLayerSet $layers, private PresentationWorld $world,
        private FieldViewport $benchmarkViewport)
    {
        // Inert owners avoid Game's launch/cleanup and SceneManager's save/battle setup.
        // The real scene constructor still initializes its camera, UI and event providers.
        $game = new BenchmarkPresentationGame();
        $manager = new ReflectionClass(SceneManager::class)->newInstanceWithoutConstructor();
        $manager->game = $game;
        parent::__construct($manager, 'PHP presentation benchmark');
        $this->camera = new Camera($this, $benchmarkViewport->columns, $benchmarkViewport->rows,
            worldSpace: $layers->getComposedGrid());
        $this->camera->setRetainedWorldAvailable(true);
    }
    public function getGraphicalSpriteProviders(): iterable { return []; }
    public function getPresentationCanvas(): ?PresentationCanvas { return null; }
    public function getPresentationWorld(): ?PresentationWorld { return $this->world; }
    public function getPresentationViewport(ConsolePresentationSnapshot|ConsolePresentationChanges $snapshot, array $sprites, array $tiles = []): ?PresentationViewport
    {
        return $this->benchmarkViewport->createViewport($snapshot, $sprites, $tiles,
            $this->world->id, $this->camera->getWorldOrigin());
    }
    public function drawMap(): void { Console::recomposeFrame($this->camera->renderMap(...), true); }
}

function summariseBenchmarkSamples(array $values): array
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    return ['count' => $count, 'min' => $values[0], 'median' => ($values[intdiv($count - 1, 2)] + $values[intdiv($count, 2)]) / 2,
        'p95' => $values[(int)ceil($count * .95) - 1], 'max' => $values[$count - 1]];
}

Console::setTerminalOutputEnabled(false);
Console::enterAlternateScreen();
Console::setLayerTracking(true);
Console::syncDimensions($columns, $rows);
LatencyTrace::configure();
$started = hrtime(true);
$layers = MapLayerSource::loadFromDirectory($mapDirectory, $map);
$data = require $mapDirectory . '/' . basename($map) . '.data.php';
if (!is_array($data)) { throw new InvalidArgumentException('Map data must return an array.'); }
// Glyph-keyed tiles2d and stateless tile batches are removed; graphics use retained tileset layers only.
$graphics = MapGraphics::loadFromDirectory($mapDirectory, $map, $data['tileset'] ?? null,
    $layers, $assets, $data[MapGraphics::SETTINGS_KEY] ?? null);
$loadMs = (hrtime(true) - $started) / 1e6;
$started = hrtime(true);
$world = PresentationWorld::getFromLayers($layers, 'map', $graphics, $assets);
$worldCompileMs = (hrtime(true) - $started) / 1e6;
$fieldViewport = new FieldViewport(new RendererGridConfig($columns, $rows, BENCHMARK_CELL_WIDTH, BENCHMARK_CELL_HEIGHT));
$scene = new BenchmarkPresentationScene($layers, $world, $fieldViewport);
$maxX = max(0, $scene->camera->worldSpaceWidth - $fieldViewport->columns);
$maxY = max(0, $scene->camera->worldSpaceHeight - $fieldViewport->rows);
if ($startX + 1 > $maxX || $startY > $maxY) { throw new InvalidArgumentException('Map must permit the requested eight distinct scroll positions.'); }
$positions = [['north', $startX, $startY - 1], ['east', $startX + 1, $startY - 1],
    ['south', $startX + 1, $startY], ['west', $startX, $startY], ['north-west', 0, 0],
    ['north-east', $maxX, 0], ['south-east', $maxX, $maxY], ['south-west', 0, $maxY]];
if (count(array_unique(array_map(static fn(array $position): string => $position[1] . ':' . $position[2], $positions))) !== 8) {
    throw new InvalidArgumentException('Map must permit the requested eight distinct scroll positions.');
}
$transport = new BenchmarkPresentationTransport();
$runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig([PHP_BINARY]), $assets,
    cellWidth: BENCHMARK_CELL_WIDTH, cellHeight: BENCHMARK_CELL_HEIGHT), $transport);
try {
    $runtime->start('PHP presentation benchmark', $columns, $rows);
    $transport->resetCounters();
    $scene->camera->moveTo($startX, $startY);
    $started = hrtime(true);
    $scene->drawMap();
    $coldCalls = 0;
    do {
        $runtime->present($scene);
        if (++$coldCalls > BENCHMARK_MAX_COLD_UPLOAD_CALLS) { throw new RuntimeException('Cold upload failed to complete.'); }
    } while ($transport->presentedFrames === 0);
    if ($transport->worldUploads !== 1) { throw new RuntimeException('Cold upload must install exactly one retained world.'); }
    $initial = ['combinedMs' => (hrtime(true) - $started) / 1e6, 'bytes' => $transport->bytes, 'packets' => $transport->packets,
        'operations' => $transport->operations, 'worldUploads' => $transport->worldUploads, 'runtimeCalls' => $coldCalls,
        'worldCells' => $transport->worldCells, 'tileCells' => $transport->tileCells,
        'presentedFrames' => $transport->presentedFrames, 'world' => $transport->worldMetadata, 'viewport' => $transport->viewport];
    $traceRecords = [];
    if (isset($options['trace'])) {
        LatencyTrace::configure(static function (array $record) use (&$traceRecords): void {
            if (isset($record['duration_ns'])) { $traceRecords[$record['stage']] = $record['duration_ns'] / 1e6; }
        });
    }
    $samples = [];
    for ($cycle = -$warmup; $cycle < $iterations; $cycle++) {
        foreach ($positions as [$label, $x, $y]) {
            $scene->camera->moveTo($x, $y);
            $transport->resetCounters();
            $traceRecords = [];
            $started = hrtime(true);
            $scene->drawMap();
            $drawn = hrtime(true);
            $changed = $runtime->present($scene);
            $finished = hrtime(true);
            if (!$changed) { throw new RuntimeException("Scroll $label unexpectedly emitted nothing."); }
            if ($transport->packets !== 1 || $transport->operations !== 0 || $transport->worldUploads !== 0
                || $transport->presentedFrames !== 1 || ($transport->viewport['worldOrigin'] ?? null) !== ['column' => $x, 'row' => $y]) {
                throw new RuntimeException('Retained scrolling must emit one viewport-only packet.');
            }
            if ($cycle >= 0) {
                $samples[] = ['cycle' => $cycle, 'position' => $label, 'drawMs' => ($drawn - $started) / 1e6,
                    'runtimeMs' => ($finished - $drawn) / 1e6, 'combinedMs' => ($finished - $started) / 1e6,
                    'transportEncodingMs' => $transport->encodeNs / 1e6, 'bytes' => $transport->bytes,
                    'packets' => $transport->packets, 'operations' => $transport->operations,
                    'tileCells' => $transport->tileCells, 'textRuns' => $transport->textRuns,
                    'worldUploads' => $transport->worldUploads, 'viewport' => $transport->viewport, 'traceMs' => $traceRecords];
            }
        }
    }
    $transport->resetCounters();
    $scene->drawMap();
    $unchanged = $runtime->present($scene);
    if ($unchanged || $transport->packets !== 0) { throw new RuntimeException('Unchanged state emitted a packet.'); }
    $unchangedPackets = $transport->packets;
} finally {
    try { $runtime->shutdown(); }
    finally { Console::reset(); LatencyTrace::configure(); }
}
$sources = [];
foreach (get_declared_classes() as $class) {
    if (!str_starts_with($class, 'Ichiloto\\Engine\\')) { continue; }
    $path = new ReflectionClass($class)->getFileName();
    if (!str_starts_with($path, $engine . '/src/')) { throw new RuntimeException("Mixed Engine source: $path"); }
    $sources[substr($path, strlen($engine) + 1)] = hash_file('sha256', $path);
}
ksort($sources);
$summary = [];
foreach (['drawMs', 'runtimeMs', 'combinedMs', 'transportEncodingMs', 'bytes', 'packets', 'operations', 'tileCells', 'textRuns'] as $metric) {
    $summary[$metric] = summariseBenchmarkSamples(array_column($samples, $metric));
}
$result = ['scope' => 'PHP map-only scroll, real Console/Camera and Runtime::present including collectors, snapshots, serializer and encoded fake transport with immediate synthetic acknowledgements. No actors/HUD/gameplay/native/GPU/pipe latency.',
    'mode' => 'retained', 'traceEnabled' => isset($options['trace']),
    'php' => PHP_VERSION, 'os' => php_uname(), 'jit' => ini_get('opcache.jit'), 'opcacheCli' => ini_get('opcache.enable_cli'),
    'engine' => $engine, 'project' => $project, 'dependencies' => $vendor, 'map' => $map,
    'grid' => [$columns, $rows], 'cellSize' => [BENCHMARK_CELL_WIDTH, BENCHMARK_CELL_HEIGHT],
    'fieldGrid' => [$fieldViewport->columns, $fieldViewport->rows],
    'fieldCellSize' => [FieldViewport::TILE_SIZE, FieldViewport::TILE_SIZE], 'fieldZoom' => $fieldViewport->zoom,
    'worldSize' => [$scene->camera->worldSpaceWidth, $scene->camera->worldSpaceHeight],
    'warmupCycles' => $warmup, 'measuredCycles' => $iterations, 'positions' => $positions, 'sources' => $sources,
    'loadMs' => $loadMs, 'worldCompileMs' => $worldCompileMs, 'initial' => $initial, 'unchangedPackets' => $unchangedPackets,
    'summary' => $summary, 'samples' => $samples];
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if ($output === null) { fwrite(STDOUT, $json); }
else {
    $stream = fopen($output, 'x');
    if ($stream === false) { throw new RuntimeException('Cannot create output.'); }
    fwrite($stream, $json);
    fclose($stream);
    fwrite(STDOUT, json_encode(['file' => $output, 'mode' => $result['mode'], 'summary' => $summary], JSON_THROW_ON_ERROR) . "\n");
}
