<?php

use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalCapabilities;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

beforeEach(function () {
    $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
    $this->capabilityState = new ReflectionClass(TerminalCapabilities::class)->getStaticProperties();
    $this->configState = new ReflectionClass(ConfigStore::class)->getStaticProperties();
    ConfigStore::remove(ProjectConfig::class);
    new ReflectionProperty(TerminalCapabilities::class, 'supportsCompositeEmoji')->setValue(null, true);
    foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false] as $key => $value) {
        new ReflectionProperty(Console::class, $key)->setValue(null, $value);
    }
    Console::setTerminalOutputEnabled(false);
    Console::setLayerTracking(true);
    Console::syncDimensions(8, 4);
    $this->records = [];
    LatencyTrace::configure(function (array $record): void { $this->records[] = $record; });
});

afterEach(function () {
    LatencyTrace::configure();
    foreach ([Console::class => $this->consoleState, TerminalCapabilities::class => $this->capabilityState,
        ConfigStore::class => $this->configState] as $class => $state) {
        foreach ($state as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
    }
});

/** The original top-down scan, kept only as a deterministic parity oracle. */
function getReferenceMapOwner(MapLayerSet $set, int $x, int $y): MapLayer
{
    $gameplay = array_values(array_filter($set->layers, static fn(MapLayer $layer): bool => !$layer->decoration));
    for ($index = count($gameplay) - 1; $index > 0; $index--) {
        if (TerminalText::stripAnsi($gameplay[$index]->grid[$y][$x] ?? ' ') !== ' ') { return $gameplay[$index]; }
    }
    return $gameplay[0];
}

/** The original composition, including authored styled spaces on the base. */
function getReferenceMapGrid(MapLayerSet $set): array
{
    $result = [];
    foreach ($set->layers as $layer) {
        if ($layer->decoration) { continue; }
        if ($result === []) { $result = $layer->grid; continue; }
        foreach ($layer->grid as $y => $row) {
            foreach ($row as $x => $cell) {
                if (TerminalText::stripAnsi($cell) !== ' ') { $result[$y][$x] = $cell; }
            }
        }
    }
    return $result;
}

it('precomputes exact styled composition glyphs and sorted gameplay owners on ragged rows', function () {
    $set = new MapLayerSet([
        new MapLayer('fixtures', 30, false, 'fixtures', " \e[33m0\e[0m \n\n \u{00a0}"),
        new MapLayer('decoration', 0, true, 'deco', "rrr\n\nrr"),
        new MapLayer('terrain', 1, false, 'terrain', "\e[44m \e[0m界e\u{0301}\n\nx "),
        new MapLayer('walls', 10, false, 'walls', "#\e[45m \e[0m \n\n  "),
    ]);
    expect($set->getComposedGrid())->toBe(getReferenceMapGrid($set))
        ->and($set->gameplayOwners)->toBe([[2, 3, 1], [], [1, 3]]);
    foreach ($set->layers as $layer) {
        foreach ($layer->grid as $y => $row) {
            foreach ($row as $x => $cell) {
                expect($layer->glyphs[$y][$x])->toBe(TerminalText::stripAnsi($cell));
            }
        }
    }
    foreach ([-1, 0, 1, 2, 3] as $y) {
        foreach ([-1, 0, 1, 2, 3] as $x) {
            expect($set->getGameplayLayerAt($x, $y))->toBe(getReferenceMapOwner($set, $x, $y));
        }
    }
    // Derived arrays are values, not mutable aliases back into the authored map.
    $copy = $set->getComposedGrid();
    $copy[0][0] = 'changed';
    $glyphs = $set->layers[1]->glyphs;
    $glyphs[0][0] = 'changed';
    expect($set->getComposedGrid())->toBe(getReferenceMapGrid($set))
        ->and($set->layers[1]->glyphs[0][0])->toBe(' ');
});

it('retains legacy empty and ragged grids without padding or changing out-of-bounds fallback', function (string $text) {
    $layer = new MapLayer('terrain', 0, false, 'legacy', $text);
    $set = new MapLayerSet([$layer], legacy: true);
    expect($set->getComposedGrid())->toBe($layer->grid)
        ->and($set->getGameplayLayerAt(-1, -1))->toBe($layer)
        ->and($set->getGameplayLayerAt(999, 999))->toBe($layer);
})->with(['', "x\nxx\n", "x\n\nx", "\e[44m \e[0m"]);

it('keeps visual ownership separate from pass-through collision resolution', function () {
    $set = new MapLayerSet([
        new MapLayer('terrain', 0, false, 'terrain', ';# '),
        new MapLayer('fixtures', 1, false, 'fixtures', 'ii '),
        new MapLayer('decoration', 2, true, 'decoration', 'www'),
    ]);
    expect($set->gameplayOwners)->toBe([[1, 1, 0]])
        ->and(MapCollisionResolver::resolveLayers($set, [';' => CollisionType::ENCOUNTER,
            '#' => CollisionType::SOLID, ' ' => CollisionType::NONE,
            'fixtures' => ['i' => CollisionType::PASS_THROUGH]]))
        ->toBe([[CollisionType::ENCOUNTER->value, CollisionType::SOLID->value, CollisionType::NONE->value]]);
});

it('owns the input layer list rather than retaining aliases that can invalidate derived state', function () {
    $layer = new MapLayer('terrain', 0, false, 'terrain', 'a');
    $original = $layer;
    $input = [&$layer];
    $set = new MapLayerSet($input);
    $layer = new MapLayer('replacement', 0, false, 'replacement', '界');
    expect($set->layers)->toBe([$original])
        ->and($set->getGameplayLayerAt(0, 0))->toBe($original)
        ->and($set->getComposedGrid())->toBe([['a']]);
});

it('slices existing normalized columns without measuring or cutting styled wide glyphs', function () {
    $row = NormalizedRow::fromSymbols(["\e[31m界\e[0m", 'e', "\u{0301}", 'x']);
    $this->records = [];
    expect($row->selectColumns(0, 1)->cells)->toBe([])
        ->and($row->selectColumns(0, 2)->cells)->toBe(array_slice($row->cells, 0, 2))
        ->and($row->selectColumns(2, 3)->cells)->toBe(array_slice($row->cells, 2, 3))
        ->and($row->selectColumns(99, 2)->cells)->toBe([])
        ->and(fn() => $row->selectColumns(1, 2))->toThrow(InvalidArgumentException::class, 'glyph anchor')
        ->and($this->records)->toBe([]);
});

it('reuses normalized map rows across pans padding and width policies without re-normalizing', function (bool $tracking) {
    Console::setLayerTracking($tracking);
    $set = new MapLayerSet([
        new MapLayer('terrain', 0, false, 'terrain', "\e[44mabcdefghijkl\e[0m\nshort\n界abcdefghi界z\nx"),
        new MapLayer('fixtures', 1, false, 'fixtures', " \e[33mi\e[0m  界  i    \n  i  \n   i        \n "),
    ]);
    $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: $set->getComposedGrid());
    foreach ([true, false, true] as $policy) {
        new ReflectionProperty(TerminalCapabilities::class, 'supportsCompositeEmoji')->setValue(null, $policy);
        $camera->moveTo(0, 0);
        Console::recomposeFrame($camera->renderMap(...));
        foreach ([[2, 0], [4, 0], [0, 0]] as [$x, $y]) {
            $camera->moveTo($x, $y);
            $this->records = [];
            Console::recomposeFrame($camera->renderMap(...));
            expect(array_column($this->records, 'stage'))
                ->not->toContain('terminal.normalize', 'terminal.tokenize', 'terminal.format');
        }
    }
    // Restoring a cell under a sprite repaints the composed cell.
    Console::write('@', 1, 0);
    $camera->renderBackgroundTile(1, 0);
    expect(Console::charAt(1, 0))->toBe('i');
})->with([false, true]);
