#!/usr/bin/env php
<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapLayerSource;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;

$options = getopt('', ['engine:', 'map:', 'layers:', 'iterations:']);
$root = $options['engine'] ?? dirname(__DIR__);
require $root . '/vendor/autoload.php';
$iterations = max(1, (int)($options['iterations'] ?? 15));

/** Report medians, never assert wall-clock thresholds in correctness tests. */
function measureMapOperation(callable $operation, int $iterations): float
{
    $operation();
    $samples = [];
    for ($sample = 0; $sample < 5; $sample++) {
        $started = hrtime(true);
        for ($index = 0; $index < $iterations; $index++) { $operation(); }
        $samples[] = (hrtime(true) - $started) / 1e6 / $iterations;
    }
    sort($samples);
    return $samples[2];
}

$create = static function () use ($options): MapLayerSet {
    if (isset($options['map'])) { return MapLayerSource::loadFromDirectory($options['map']); }
    $count = max(1, min(99, (int)($options['layers'] ?? 3)));
    $layers = [];
    for ($index = 0; $index < $count; $index++) {
        $rows = [];
        for ($y = 0; $y < 81; $y++) {
            $row = '';
            for ($x = 0; $x < 221; $x++) {
                $row .= $index === 0 ? '.' : (($x + $y + $index) % 37 === 0 ? "\e[33m#\e[0m" : ' ');
            }
            $rows[] = $row;
        }
        $layers[] = new MapLayer('layer' . $index, $index, false, 'synthetic/' . $index, implode("\n", $rows));
    }
    return new MapLayerSet($layers);
};
$before = memory_get_usage();
$started = hrtime(true);
$layers = $create();
$construction = (hrtime(true) - $started) / 1e6;
$mapBytes = memory_get_usage() - $before;
$grid = $layers->getComposedGrid();
$height = count($grid);
$width = max(array_map(count(...), $grid));
$ownerChecksum = 0;
$owners = measureMapOperation(static function () use ($layers, $grid, &$ownerChecksum): void {
    $sum = 0;
    foreach ($grid as $y => $row) {
        foreach ($row as $x => $_) { $sum += $layers->getGameplayLayerAt($x, $y)->order; }
    }
    $ownerChecksum = $sum;
}, $iterations);
$composition = measureMapOperation(static fn() => $layers->getComposedGrid(), $iterations);
$metricChecksum = 0;
$metrics = measureMapOperation(static function () use ($layers, &$metricChecksum): void {
    $sum = 0;
    foreach ($layers->layers as $layer) {
        $glyphs = $layer->glyphs ?? null;
        $widths = method_exists($layer, 'getWidths') ? $layer->getWidths() : null;
        foreach ($layer->grid as $y => $row) {
            foreach ($row as $x => $cell) {
                $sum += strlen($glyphs[$y][$x] ?? TerminalText::stripAnsi($cell));
                $sum += $widths[$y][$x] ?? NormalizedRow::symbolWidth($cell);
            }
        }
    }
    $metricChecksum = $sum;
}, $iterations);
$camera = new ReflectionClass(Camera::class)->newInstanceWithoutConstructor();
$camera->screen = new Rect(0, 0, 200, 50);
$camera->worldSpace = $grid;
Console::setTerminalOutputEnabled(false);
Console::syncDimensions(200, 50);
Console::setLayerTracking(true);
$scroll = 0;
$render = measureMapOperation(static function () use ($layers, $camera, &$scroll, $width, $height): void {
    $camera->screen->setX($scroll % max(1, $width - 200 + 1));
    $camera->screen->setY($scroll % max(1, $height - 50 + 1));
    $scroll++;
    $camera->renderLayeredMap($layers);
}, $iterations);
$camera->screen->setX(0);
$camera->screen->setY(0);
$camera->renderLayeredMap($layers);
echo json_encode([
    'php' => PHP_VERSION, 'map' => $options['map'] ?? 'synthetic',
    'layers' => count($layers->layers), 'width' => $width, 'height' => $height,
    'iterations_per_sample' => $iterations, 'samples' => 5,
    'construction_ms' => $construction, 'map_retained_bytes' => $mapBytes,
    'owner_scan_ms' => $owners, 'composed_grid_ms' => $composition,
    'layer_metrics_scan_ms' => $metrics, 'terminal_layered_200x50_scroll_ms' => $render,
    'owner_checksum' => $ownerChecksum, 'metric_checksum' => $metricChecksum,
    'composed_sha256' => hash('sha256', serialize($grid)),
    'terminal_snapshot_sha256' => hash('sha256', serialize(Console::presentationSnapshot())),
    'peak_bytes' => memory_get_peak_usage(true),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
