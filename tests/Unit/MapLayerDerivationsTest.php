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
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
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

/** Original group re-normalization, used to compare exact layered provenance. */
function renderReferenceLayeredMap(Camera $camera, MapLayerSet $set): void
{
    $offset = new ReflectionMethod(Camera::class, 'getRenderOffset')->invoke($camera);
    $width = new ReflectionMethod(Camera::class, 'getVisibleWorldWidth')->invoke($camera);
    $height = new ReflectionMethod(Camera::class, 'getVisibleWorldHeight')->invoke($camera);
    for ($row = 0; $row < $height; $row++) {
        $worldY = (int)$camera->position->y + $row;
        $content = new ReflectionMethod(Camera::class, 'normalizedMapRow')->invoke($camera, $worldY)
            ->select((int)$camera->position->x, $width, $width);
        $logicalX = (int)$camera->position->x;
        $owner = null;
        $group = [];
        $start = 0;
        foreach ($content->cells as $column => $cell) {
            if ($cell === NormalizedRow::CONTINUATION) { continue; }
            $next = getReferenceMapOwner($set, $logicalX++, $worldY);
            if ($owner !== null && $owner !== $next) {
                PresentationLayerPolicy::drawMapLayer($owner, fn() => Console::writeNormalizedRow(
                    NormalizedRow::fromSymbols($group), $offset->x + $start, $offset->y + $row));
                $group = [];
            }
            if ($group === []) { $start = $column; }
            $group[] = $cell;
            $owner = $next;
        }
        if ($owner !== null) {
            PresentationLayerPolicy::drawMapLayer($owner, fn() => Console::writeNormalizedRow(
                NormalizedRow::fromSymbols($group), $offset->x + $start, $offset->y + $row));
        }
    }
}

it('precomputes exact styled composition glyphs widths and sorted gameplay owners on ragged rows', function () {
    $set = new MapLayerSet([
        new MapLayer('fixtures', 30, false, 'fixtures', " \e[33m0\e[0m \n\n \u{00a0}"),
        new MapLayer('decoration', 0, true, 'deco', "rrr\n\nrr"),
        new MapLayer('terrain', 1, false, 'terrain', "\e[44m \e[0m界e\u{0301}\n\nx "),
        new MapLayer('walls', 10, false, 'walls', "#\e[45m \e[0m \n\n  "),
    ]);
    expect($set->getComposedGrid())->toBe(getReferenceMapGrid($set))
        ->and($set->gameplayOwners)->toBe([[2, 3, 1], [], [1, 3]])
        ->and(array_map(count(...), $set->getComposedWidths()))->toBe([3, 0, 2]);
    foreach ($set->layers as $layer) {
        foreach ($layer->grid as $y => $row) {
            foreach ($row as $x => $cell) {
                expect($layer->glyphs[$y][$x])->toBe(TerminalText::stripAnsi($cell))
                    ->and($layer->getWidths()[$y][$x])->toBe(NormalizedRow::symbolWidth($cell));
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
    $widths = $set->getComposedWidths();
    $widths[0][0] = 99;
    expect($set->getComposedGrid())->toBe(getReferenceMapGrid($set))
        ->and($set->layers[1]->glyphs[0][0])->toBe(' ')
        ->and($set->getComposedWidths()[0][0])->toBe(1);
});

it('retains legacy empty and ragged grids without padding or changing out-of-bounds fallback', function (string $text) {
    $layer = new MapLayer('terrain', 0, false, 'legacy', $text);
    $set = new MapLayerSet([$layer], legacy: true);
    expect($set->getComposedGrid())->toBe($layer->grid)
        ->and(array_map(count(...), $set->getComposedWidths()))->toBe(array_map(count(...), $layer->grid))
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
        ->and($set->getComposedGrid())->toBe([['a']])
        ->and($set->getComposedWidths())->toBe([[1]]);
});

it('measures static rows only at construction or a new terminal policy and reuses both policies', function () {
    $layer = new MapLayer('terrain', 0, false, 'terrain', "a\u{200d}界🏃🏽‍➡️\n\e[31m🗡️\e[0m");
    $set = new MapLayerSet([$layer]);
    $initial = $layer->getWidths();
    $this->records = [];
    foreach ([false, false, true, false, true] as $policy) {
        new ReflectionProperty(TerminalCapabilities::class, 'supportsCompositeEmoji')->setValue(null, $policy);
        $widths = $layer->getWidths();
        $composed = $set->getComposedWidths();
        foreach ($layer->grid as $y => $row) {
            foreach ($row as $x => $cell) {
                expect($widths[$y][$x])->toBe(NormalizedRow::symbolWidth($cell))
                    ->and($composed[$y][$x])->toBe($widths[$y][$x])
                    ->and(in_array($widths[$y][$x], [1, 2], true))->toBeTrue();
            }
        }
    }
    expect($layer->getWidths())->toBe($initial)
        ->and(array_count_values(array_column($this->records, 'stage'))['map.measure'])->toBe(2);
    $this->records = [];
    for ($index = 0; $index < 20; $index++) {
        $set->getComposedGrid();
        $set->getComposedWidths();
        $set->getGameplayLayerAt(1, 0);
        $layer->glyphs;
        $layer->getWidths();
    }
    expect($this->records)->toBe([]);
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

it('reuses layered row cells across pans padding width policies and provenance without re-normalizing groups', function (bool $tracking) {
    Console::setLayerTracking($tracking);
    $set = new MapLayerSet([
        new MapLayer('terrain', 0, false, 'terrain', "\e[44mabcdefghijkl\e[0m\nshort\n界abcdefghi界z\nx"),
        new MapLayer('fixtures', 1, false, 'fixtures', " \e[33mi\e[0m  界  i    \n  i  \n   i        \n "),
    ]);
    $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: $set->getComposedGrid());
    foreach ([true, false, true] as $policy) {
        new ReflectionProperty(TerminalCapabilities::class, 'supportsCompositeEmoji')->setValue(null, $policy);
        foreach ([[0, 0], [2, 0], [4, 0], [0, 0]] as [$x, $y]) {
            $camera->moveTo($x, $y);
            Console::recomposeFrame($camera->renderMap(...));
            $expected = Console::snapshot();
            $this->records = [];
            Console::recomposeFrame(fn() => $camera->renderLayeredMap($set));
            expect(Console::snapshot())->toEqual($expected)
                ->and(array_column($this->records, 'stage'))
                ->not->toContain('terminal.normalize', 'terminal.tokenize', 'terminal.format', 'map.measure');
        }
    }
    if ($tracking) {
        $layers = array_column(Console::presentationSnapshot()->textLayers, null, 'id');
        expect($layers)->toHaveKeys(['map:terrain', 'map:fixtures']);
        $owner = $set->getGameplayLayerAt(1, 0);
        Console::write('@', 1, 0);
        PresentationLayerPolicy::drawMapLayer($owner, fn() => $camera->renderBackgroundTile(1, 0));
        expect(Console::charAt(1, 0))->toBe('i');
    }
})->with([false, true]);

it('matches the original normalized group bytes and layer provenance across both policies and viewport bounds', function (string $text) {
    $symbols = MapLayer::parseGrid($text)[0];
    $overlay = array_fill(0, count($symbols), ' ');
    foreach ($overlay as $index => $_) {
        if ($index % 3 === 1) { $overlay[$index] = "\e[38;2;0;128;0m{$symbols[$index]}\e[0m"; }
    }
    $set = new MapLayerSet([
        new MapLayer('terrain', 0, false, 'terrain', $text . "\nx"),
        new MapLayer('fixtures', 1, false, 'fixtures', implode('', $overlay) . "\n "),
    ]);
    foreach ([true, false] as $policy) {
        new ReflectionProperty(TerminalCapabilities::class, 'supportsCompositeEmoji')->setValue(null, $policy);
        foreach ([1, 3, 8, 16] as $width) {
            Console::syncDimensions($width, 4);
            $camera = new Camera(makeCameraTestScene(), $width, 4, worldSpace: $set->getComposedGrid());
            foreach ([0, 1, 3] as $x) {
                $camera->moveTo($x, 0);
                Console::recomposeFrame(fn() => renderReferenceLayeredMap($camera, $set));
                $expected = Console::presentationSnapshot();
                Console::recomposeFrame(fn() => $camera->renderLayeredMap($set));
                expect(Console::presentationSnapshot())->toEqual($expected);
            }
        }
    }
})->with([
    'abcdefghi', "\e[44m a b c \e[0m", '界ab界cde', "e\e[0m\u{0301}abc",
    "🇺\e[0m🇸X", "a\u{200d}b🏃🏽‍➡️x", "\e[31m🗡️\e[0m ⚔x", "a\0bc",
]);
