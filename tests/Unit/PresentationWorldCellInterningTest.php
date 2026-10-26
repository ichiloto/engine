<?php

declare(strict_types=1);

use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;

function createPresentationWorldInterningLayers(): MapLayerSet
{
    $row = "\e[31;44mXX\e[0m\e[32;45mXX\e[0mXX";
    return new MapLayerSet([
        new MapLayer('terrain', 0, false, 'terrain', $row . "\n" . $row),
        new MapLayer('fixtures', 1, false, 'fixtures', "  \e[31;44mXX\e[0m  \n      "),
        new MapLayer('canopy', 2, true, 'canopy', "!!!!!!\n!!!!!!"),
    ]);
}

/** Known synthetic styles keep the expected wire independent of the implementation's cache. */
function createPresentationWorldInterningCell(string $owner, ?int $foreground = null, ?int $background = null,
    string $glyph = 'X'): array
{
    return ['glyph' => $glyph,
        'foreground' => $foreground === null ? null : ['kind' => 'ansi16', 'index' => $foreground],
        'background' => $background === null ? null : ['kind' => 'ansi16', 'index' => $background],
        'ownerLayerId' => 'map:' . $owner];
}

it('preserves every row operation, owner, style and native source charge for repeated cells', function () {
    $world = PresentationWorld::getFromLayers(createPresentationWorldInterningLayers(), 'synthetic');
    $red = createPresentationWorldInterningCell('terrain', 1, 4);
    $green = createPresentationWorldInterningCell('terrain', 2, 5);
    $fixture = createPresentationWorldInterningCell('fixtures', 1, 4);
    $plain = createPresentationWorldInterningCell('terrain');
    $expected = [
        ['op' => 'put', 'kind' => 'world', 'id' => 'synthetic', 'value' => [
            'columns' => 6, 'rows' => 2, 'cellWidth' => 48, 'cellHeight' => 48, 'layers' => [
                ['id' => 'map:terrain', 'layer' => -100, 'kind' => 'gameplay'],
                ['id' => 'map:fixtures', 'layer' => -99, 'kind' => 'gameplay'],
                ['id' => 'map:canopy', 'layer' => -98, 'kind' => 'decoration'],
            ],
        ]],
        ['op' => 'worldRows', 'id' => 'synthetic', 'rows' => [
            ['row' => 0, 'cells' => [$red, $red, $fixture, $fixture, $plain, $plain]],
        ]],
        ['op' => 'worldRows', 'id' => 'synthetic', 'rows' => [
            ['row' => 1, 'cells' => [$red, $red, $green, $green, $plain, $plain]],
        ]],
    ];
    expect($world->operations)->toBe($expected)
        ->and($world->getOperations(false))->toBe($expected)
        ->and($world->getOperations(true, true))->toBe($expected)
        ->and(json_decode(json_encode($world->operations, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toBe($expected)
        ->and($world->textLayerIds)->toBe(['map:terrain', 'map:fixtures', 'map:canopy'])
        // Twelve wire cells are charged independently, even when their PHP storage is shared.
        ->and($world->estimatedSourceBytes)->toBe(3 * 8192 + 10 * (64 + 1 + 11) + 2 * (64 + 1 + 12));
});

it('isolates nested caller edits from equal cells, other rows, other callers, source layers and worlds', function () {
    $layers = createPresentationWorldInterningLayers();
    $world = PresentationWorld::getFromLayers($layers, 'synthetic');
    $otherWorld = PresentationWorld::getFromLayers($layers, 'synthetic');
    $original = $world->operations;
    $firstCaller = $world->getOperations(false);
    $secondCaller = $world->getOperations(true);
    $firstCaller[1]['rows'][0]['cells'][0]['foreground']['index'] = 7;
    $firstCaller[1]['rows'][0]['cells'][0]['background']['index'] = 3;
    $firstCaller[1]['rows'][0]['cells'][0]['ownerLayerId'] = 'map:changed';
    $firstCaller[1]['rows'][0]['cells'][0]['glyph'] = '?';
    $source = $layers->getComposedGrid();
    $source[0][0] = '?';
    expect($firstCaller[1]['rows'][0]['cells'][0])->toBe(createPresentationWorldInterningCell('changed', 7, 3, '?'))
        ->and($firstCaller[1]['rows'][0]['cells'][1])->toBe($original[1]['rows'][0]['cells'][1])
        ->and($firstCaller[2]['rows'][0]['cells'][0])->toBe($original[2]['rows'][0]['cells'][0])
        ->and($secondCaller)->toBe($original)
        ->and($world->operations)->toBe($original)
        ->and($world->getOperations(false))->toBe($original)
        ->and($otherWorld->operations)->toBe($original)
        ->and($layers->getComposedGrid()[0][0])->not->toBe('?')
        ->and(PresentationWorld::getFromLayers($layers, 'synthetic')->operations)->toBe($original);
});

it('keeps coverage-projected owners distinct without modifying gameplay ownership or another world', function () {
    $row = "\e[31;44mXX\e[0m";
    $layers = new MapLayerSet([
        new MapLayer('terrain', 0, false, 'terrain', $row),
        new MapLayer('fixtures', 1, false, 'fixtures', $row),
    ]);
    $world = PresentationWorld::getFromLayers($layers, coverage: ['glyphs' => ['fixtures' => [0 => [1 => true]]]]);
    $other = PresentationWorld::getFromLayers($layers, coverage: ['glyphs' => ['fixtures' => [0 => [0 => true]]]]);
    $fixture = createPresentationWorldInterningCell('fixtures', 1, 4);
    $terrain = createPresentationWorldInterningCell('terrain', 1, 4);
    expect($world->operations[1]['rows'][0]['cells'])->toBe([$fixture, $terrain])
        ->and($other->operations[1]['rows'][0]['cells'])->toBe([$terrain, $fixture])
        ->and($world->estimatedSourceBytes)->toBe(2 * 8192 + 77 + 76)
        ->and($layers->getGameplayLayerAt(0, 0)->name)->toBe('fixtures')
        ->and($layers->getGameplayLayerAt(1, 0)->name)->toBe('fixtures')
        ->and(PresentationWorld::getFromLayers($layers)->operations[1]['rows'][0]['cells'])->toBe([$fixture, $fixture]);
});

it('retains ragged and empty row operations and styles on blank cells in legacy layers', function () {
    $layers = new MapLayerSet([new MapLayer('terrain', 0, false, 'legacy', "X \e[44m \e[0m\n\n\e[31mX\e[0m")], legacy: true);
    $world = PresentationWorld::getFromLayers($layers);
    expect($world->operations)->toHaveCount(4)
        ->and($world->operations[0]['value']['columns'])->toBe(3)
        ->and($world->operations[0]['value']['rows'])->toBe(3)
        ->and($world->operations[1])->toBe(['op' => 'worldRows', 'id' => 'map', 'rows' => [['row' => 0, 'cells' => [
            createPresentationWorldInterningCell('terrain'),
            createPresentationWorldInterningCell('terrain', glyph: ' '),
            createPresentationWorldInterningCell('terrain', background: 4, glyph: ' '),
        ]]]])
        ->and($world->operations[2])->toBe(['op' => 'worldRows', 'id' => 'map', 'rows' => [['row' => 1, 'cells' => []]]])
        ->and($world->operations[3])->toBe(['op' => 'worldRows', 'id' => 'map', 'rows' => [['row' => 2, 'cells' => [
            createPresentationWorldInterningCell('terrain', 1),
        ]]]])
        ->and($world->estimatedSourceBytes)->toBe(8192 + 4 * (64 + 1 + 11));
});

it('preserves bounded layer, extent and cell rejection without poisoning subsequent worlds', function (string $limit) {
    $valid = createPresentationWorldInterningLayers();
    $original = PresentationWorld::getFromLayers($valid)->operations;
    $layers = match ($limit) {
        'layers' => new MapLayerSet(array_map(static fn(int $index): MapLayer =>
            new MapLayer('layer' . $index, $index, false, 'layer', 'X'), range(0, 64))),
        'extent' => new MapLayerSet([new MapLayer('terrain', 0, false, 'extent', str_repeat('X', 16385))]),
        // The rectangular extent exceeds the budget with only a few authored cells in ragged rows.
        'cells' => new MapLayerSet([new MapLayer('terrain', 0, false, 'cells',
            str_repeat('X', 1025) . str_repeat("\nX", 1024))], legacy: true),
    };
    expect(PresentationWorld::MAX_LAYERS)->toBe(64)
        ->and(PresentationWorld::MAX_EXTENT)->toBe(16384)
        ->and(PresentationWorld::MAX_CELLS)->toBe(1048576)
        ->and(PresentationWorld::MAX_SOURCE_BYTES)->toBe(67108864)
        ->and(fn() => PresentationWorld::getFromLayers($layers))->toThrow(InvalidArgumentException::class,
            'Retained world exceeds the bounded layer or cell budget.')
        ->and(PresentationWorld::getFromLayers($valid)->operations)->toBe($original);
})->with(['layers', 'extent', 'cells']);

/** The budget applies to repeated cells, not arbitrarily many unique glyph/style/owner combinations. */
function getPresentationWorldInterningProbe(): string
{
    return <<<'PHP'
    require $argv[1] . '/vendor/autoload.php';
    $mode = $argv[2];
    $size = 400;
    $row = match ($mode) {
        'uniform' => str_repeat('X', $size),
        'styled' => "\e[31;44m" . str_repeat('X', $size) . "\e[0m",
        'patterned' => str_repeat("\e[31;44mX\e[0m\e[32;45mX\e[0mYZ", intdiv($size, 4)),
    };
    $layers = [new \Ichiloto\Engine\Field\MapLayer('terrain', 0, false, 'terrain',
        implode("\n", array_fill(0, $size, $row)))];
    if ($mode === 'patterned') {
        $layers[] = new \Ichiloto\Engine\Field\MapLayer('fixtures', 1, false, 'fixtures',
            implode("\n", array_fill(0, $size, str_repeat("  \e[31;44mX\e[0m ", intdiv($size, 4)))));
        $layers[] = new \Ichiloto\Engine\Field\MapLayer('canopy', 2, true, 'canopy',
            implode("\n", array_fill(0, $size, str_repeat('!', $size))));
    }
    $layers = new \Ichiloto\Engine\Field\MapLayerSet($layers);
    \Ichiloto\Engine\Rendering\Presentation\PresentationWorld::getFromLayers(new \Ichiloto\Engine\Field\MapLayerSet([
        new \Ichiloto\Engine\Field\MapLayer('warmup', 0, false, 'warmup', 'X'),
    ]));
    unset($row);
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $world = \Ichiloto\Engine\Rendering\Presentation\PresentationWorld::getFromLayers($layers, 'synthetic');
    $additionalPeakBytes = memory_get_peak_usage() - $before;
    $peakBytes = memory_get_peak_usage(true);
    $charge = count($world->operations[0]['value']['layers']) * 8192;
    $count = $rowCount = 0;
    $owners = $variants = [];
    $complete = true;
    foreach ($world->operations as $index => $operation) {
        if ($index === 0) { continue; }
        $complete = $complete && $operation['op'] === 'worldRows' && $operation['id'] === 'synthetic'
            && count($operation['rows']) === 1 && $operation['rows'][0]['row'] === $index - 1
            && count($operation['rows'][0]['cells']) === $size;
        $rowCount++;
        foreach ($operation['rows'][0]['cells'] as $cell) {
            $count++;
            $charge += 64 + strlen($cell['glyph']) + strlen($cell['ownerLayerId']);
            $owners[$cell['ownerLayerId']] = ($owners[$cell['ownerLayerId']] ?? 0) + 1;
            $variants[json_encode($cell, JSON_THROW_ON_ERROR)] = $cell;
        }
    }
    echo json_encode(['memoryLimit' => ini_get('memory_limit'), 'additionalPeakBytes' => $additionalPeakBytes,
        'peakBytes' => $peakBytes, 'sourceBytes' => $world->estimatedSourceBytes, 'wireCharge' => $charge,
        'cellCount' => $count, 'rowCount' => $rowCount, 'operationCount' => count($world->operations),
        'complete' => $complete, 'owners' => $owners, 'variants' => array_values($variants)], JSON_THROW_ON_ERROR);
    PHP;
}

function runPresentationWorldInterningProbe(string $mode): array
{
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'max_execution_time=15',
        '-d', 'error_reporting=-1', '-r', getPresentationWorldInterningProbe(), dirname(__DIR__, 2), $mode],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not start world cell memory probe.'); }
    try {
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            if (hrtime(true) >= $deadline) { throw new RuntimeException('World cell memory probe exceeded 20 seconds.'); }
            usleep(10_000);
        } while (true);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
    } finally {
        if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
        foreach ($pipes as $pipe) { fclose($pipe); }
        proc_close($process);
    }
    expect($status['exitcode'])->toBe(0, $stderr)->and($stderr)->toBe('');
    return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
}

it('bounds repeated-cell upload allocation while retaining all styled multi-owner wire cells under 128 MB', function (string $mode) {
    $result = runPresentationWorldInterningProbe($mode);
    $expected = match ($mode) {
        'uniform' => [createPresentationWorldInterningCell('terrain')],
        'styled' => [createPresentationWorldInterningCell('terrain', 1, 4)],
        'patterned' => [createPresentationWorldInterningCell('terrain', 1, 4),
            createPresentationWorldInterningCell('terrain', 2, 5), createPresentationWorldInterningCell('fixtures', 1, 4),
            createPresentationWorldInterningCell('terrain', glyph: 'Z')],
    };
    expect($result['memoryLimit'])->toBe('128M')
        ->and($result['additionalPeakBytes'])->toBeLessThan(12 * 1024 * 1024)
        ->and($result['peakBytes'])->toBeLessThan(128 * 1024 * 1024)
        ->and($result['complete'])->toBeTrue()
        ->and($result['cellCount'])->toBe(160000)
        ->and($result['rowCount'])->toBe(400)
        ->and($result['operationCount'])->toBe(401)
        ->and($result['sourceBytes'])->toBe($result['wireCharge'])
        ->and($result['sourceBytes'])->toBe($mode === 'patterned' ? 12224576 : 12168192)
        ->and($result['owners'])->toBe($mode === 'patterned' ? ['map:terrain' => 120000, 'map:fixtures' => 40000]
            : ['map:terrain' => 160000])
        ->and($result['variants'])->toBe($expected);
})->with(['uniform', 'styled', 'patterned']);


it('transmits repeated styled owners as lossless runs only when the renderer supports them', function () {
    $world = PresentationWorld::getFromLayers(createPresentationWorldInterningLayers(), 'synthetic');
    $ordinary = $world->getOperations(true, true);
    $compact = iterator_to_array($world->streamOperations(true, true, true), false);
    $expanded = $compact;
    foreach ($expanded as &$operation) {
        if ($operation['op'] !== 'worldRowRuns') { continue; }
        $operation['op'] = 'worldRows';
        foreach ($operation['rows'] as &$row) {
            $row['cells'] = [];
            foreach ($row['runs'] as $run) {
                array_push($row['cells'], ...array_fill(0, $run['count'], $run['cell']));
            }
            unset($row['runs']);
        }
        unset($row);
    }
    unset($operation);
    expect($expanded)->toBe($ordinary)
        ->and(strlen(json_encode($compact)))->toBeLessThan(strlen(json_encode($ordinary)))
        ->and(iterator_to_array(new \Ichiloto\Engine\Rendering\Presentation\RetainedWorldUpload($world, true, true), false))->toBe($ordinary);
    // Distinct, alternating cells and empty ragged rows keep their smaller ordinary representation.
    $alternating = PresentationWorld::getFromLayers(new MapLayerSet([
        new MapLayer('terrain', 0, false, 'synthetic', 'XYXYXY' . "\n\n" . 'Z'),
    ], legacy: true));
    expect(iterator_to_array($alternating->streamOperations(false, false, true), false))->toBe($alternating->operations);
});
