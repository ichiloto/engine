<?php

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\PresentationTileBatch;
use Ichiloto\Engine\Rendering\Tiles\GraphicalTileCollector;
use Ichiloto\Engine\Rendering\Tiles\GraphicalTileDefinition;

function getCellArtworkCrop(int $x = 0): array
{
    return ['x' => $x, 'y' => 0, 'width' => 16, 'height' => 32];
}

function getCellArtworkOverride(int $column, int $row, int $x = 0): array
{
    return ['column' => $column, 'row' => $row, 'source' => getCellArtworkCrop($x)];
}

beforeEach(function () {
    $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
    foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false] as $name => $value) {
        new ReflectionProperty(Console::class, $name)->setValue(null, $value);
    }
    Console::setTerminalOutputEnabled(false);
    Console::syncDimensions(8, 4);
    Console::setLayerTracking(true);
});

afterEach(function () {
    foreach ($this->consoleState as $name => $value) {
        new ReflectionProperty(Console::class, $name)->setValue(null, $value);
    }
});

it('prefers explicit authored cells over symbol defaults and shares identical source rectangles', function () {
    $definition = GraphicalTileDefinition::fromArray([
        'asset' => 'furniture.png', 'symbols' => ['#' => getCellArtworkCrop()],
        'cells' => [getCellArtworkOverride(1, 0, 16), getCellArtworkOverride(2, 0)],
    ], 'inn.data.php');
    expect($definition->getSourceIndex('#', 0, 0))->toBe(0)
        ->and($definition->getSourceIndex('#', 1, 0))->toBe(1)
        ->and($definition->getSourceIndex(' ', 2, 0))->toBe(0)
        ->and($definition->getSourceIndex('i', 0, 0))->toBeNull()
        ->and($definition->hasCellOverride(2, 0))->toBeTrue()
        ->and($definition->hasCellOverride(0, 0))->toBeFalse()
        ->and($definition->cells)->toBe([0 => [1 => 1, 2 => 0]])
        ->and($definition->sources)->toHaveCount(2);
});

it('refuses malformed overrides with the authored map context', function (array $data) {
    expect(fn() => GraphicalTileDefinition::fromArray(['asset' => 'furniture.png'] + $data, 'inn.data.php'))
        ->toThrow(InvalidArgumentException::class, 'inn.data.php tiles2d:');
})->with([
    'empty' => [[]],
    'empty mappings' => [['symbols' => [], 'cells' => []]],
    'null symbols' => [['symbols' => null, 'cells' => [getCellArtworkOverride(0, 0)]]],
    'null cells' => [['cells' => null]],
    'non-list' => [['cells' => ['named' => getCellArtworkOverride(0, 0)]]],
    'duplicate' => [['cells' => [getCellArtworkOverride(1, 0), getCellArtworkOverride(1, 0, 16)]]],
    'negative row' => [['cells' => [getCellArtworkOverride(0, -1)]]],
    'negative column' => [['cells' => [getCellArtworkOverride(-1, 0)]]],
    'string column' => [['cells' => [['column' => '0', 'row' => 0, 'source' => getCellArtworkCrop()]]]],
    'float row' => [['cells' => [['column' => 0, 'row' => 0.0, 'source' => getCellArtworkCrop()]]]],
    'missing rectangle' => [['cells' => [['column' => 0, 'row' => 0]]]],
    'unknown field' => [['cells' => [getCellArtworkOverride(0, 0) + ['targetLayer' => 'other']]]],
    'bad rectangle' => [['cells' => [['column' => 0, 'row' => 0, 'source' => ['x' => 0]]]]],
    'unknown rectangle field' => [['cells' => [['column' => 0, 'row' => 0,
        'source' => getCellArtworkCrop() + ['alpha' => true]]]]],
]);

it('bounds override counts and deduplicated source counts using the shared renderer limits', function () {
    $tooManyCells = array_fill(0, PresentationTileBatch::MAX_CELLS + 1, getCellArtworkOverride(0, 0));
    expect(fn() => GraphicalTileDefinition::fromArray(['asset' => 'furniture.png', 'cells' => $tooManyCells], 'inn'))
        ->toThrow(InvalidArgumentException::class, 'catalog limits');
    $tooManySources = array_map(static fn(int $x) => getCellArtworkOverride($x, 0, $x),
        range(0, PresentationTileBatch::MAX_SOURCES));
    expect(fn() => GraphicalTileDefinition::fromArray(['asset' => 'furniture.png', 'cells' => $tooManySources], 'inn'))
        ->toThrow(InvalidArgumentException::class, 'source limit');
    $shared = array_map(static fn(int $x) => getCellArtworkOverride($x, 0),
        range(0, PresentationTileBatch::MAX_SOURCES));
    expect(GraphicalTileDefinition::fromArray(['asset' => 'furniture.png', 'cells' => $shared], 'inn')->sources)
        ->toHaveCount(1);
});

it('checks overrides against each authored ragged row on layered and legacy maps', function (bool $legacy) {
    $set = new MapLayerSet([new MapLayer('terrain', 1, false, 'inn/layers/01.terrain.map.php', "##\n#")], $legacy);
    $wrap = static fn(array $definition) => $legacy ? $definition : ['layers' => ['terrain' => $definition]];
    $data = ['asset' => 'furniture.png', 'cells' => [getCellArtworkOverride(0, 1)]];
    expect(GraphicalTileDefinition::getForLayers($wrap($data), $set, 'inn.data.php')['terrain']->hasCellOverride(0, 1))
        ->toBeTrue();
    foreach ([[1, 1], [0, 2]] as [$column, $row]) {
        $data['cells'] = [getCellArtworkOverride($column, $row)];
        expect(fn() => GraphicalTileDefinition::getForLayers($wrap($data), $set, 'inn.data.php'))
            ->toThrow(InvalidArgumentException::class, "inn.data.php tiles2d: cell override at row $row, column $column lies outside layer");
    }
})->with([false, true]);

it('accepts explicit decoration coverage without admitting unmapped decoration cells', function () {
    $set = new MapLayerSet([new MapLayer('ground', 1, false, 'ground', '  '),
        new MapLayer('details', 2, true, 'details.deco.php', 'xx')]);
    $data = ['asset' => 'furniture.png', 'layers' => ['details' => ['cells' => [
        getCellArtworkOverride(0, 0), getCellArtworkOverride(1, 0, 16),
    ]]]];
    $definitions = GraphicalTileDefinition::getForLayers($data, $set, 'inn');
    GraphicalTileDefinition::validateDecoration($set, $definitions);
    array_pop($data['layers']['details']['cells']);
    expect(fn() => GraphicalTileDefinition::validateDecoration($set,
        GraphicalTileDefinition::getForLayers($data, $set, 'inn')))
        ->toThrow(InvalidArgumentException::class, "glyph 'x' at row 0, column 1");
});

it('replaces repeated fixture glyphs only on their owning layer and leaves gameplay and later UI intact', function () {
    $set = new MapLayerSet([
        new MapLayer('ground', 1, false, 'ground', '      '),
        new MapLayer('floors', 2, true, 'floors', 'rrrrrr'),
        new MapLayer('fixtures', 3, false, 'fixtures', '## ##i'),
    ]);
    $beforeGrid = $set->getComposedGrid();
    $dictionary = [' ' => CollisionType::NONE, '#' => CollisionType::SOLID, 'i' => CollisionType::PASS_THROUGH];
    $beforeCollision = MapCollisionResolver::resolveLayers($set, $dictionary);
    $definitions = GraphicalTileDefinition::getForLayers(['asset' => 'furniture.png', 'layers' => [
        'floors' => ['symbols' => ['r' => getCellArtworkCrop()]],
        'fixtures' => ['cells' => array_map(static fn(int $x) => getCellArtworkOverride($x, 0, $x * 16), range(0, 4))],
    ]], $set, 'inn');
    $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: $beforeGrid);
    Console::recomposeFrame(fn() => $camera->renderLayeredMap($set));
    Console::withLayer('hud', fn() => Console::write('?', 2, 1), 1000);
    $terminal = Console::snapshot();
    $batches = new GraphicalTileCollector()->collectLayers($set, $definitions, $camera);
    expect($batches[1]->id)->toBe('map:fixtures')->and($batches[1]->cells)->toBe(array_map(
        static fn(int $x) => ['column' => $x + 1, 'row' => 1, 'source' => $x], range(0, 4)));
    $mask = [];
    foreach ($batches as $batch) {
        foreach ($batch->cells as $cell) {
            $mask[$batch->id][$cell['row']][$cell['column']] = true;
        }
    }
    $text = array_column(Console::presentationSnapshot([], $mask)->textLayers, null, 'id');
    expect($text['map:fixtures']->runs)->toHaveCount(1)
        ->and($text['map:fixtures']->runs[0]->text)->toBe('i')
        ->and($text['map:fixtures']->runs[0]->column)->toBe(6)
        ->and($text['hud']->runs[0]->text)->toBe('?')
        ->and($text['map:ground']->runs[0]->column)->toBe(3)
        ->and(Console::snapshot())->toEqual($terminal)
        ->and($set->getComposedGrid())->toBe($beforeGrid)
        ->and(MapCollisionResolver::resolveLayers($set, $dictionary))->toBe($beforeCollision);
});

it('keeps per-cell artwork tied to world coordinates through clipping and legacy collection', function (bool $legacy) {
    $set = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', "##########\n##########")], $legacy);
    $definition = ['asset' => 'parts.png', 'cells' => [getCellArtworkOverride(1, 0), getCellArtworkOverride(8, 1, 16)]];
    $definitions = GraphicalTileDefinition::getForLayers($legacy ? $definition : ['layers' => ['terrain' => $definition]], $set, 'inn');
    $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: $set->getComposedGrid());
    $collector = new GraphicalTileCollector();
    expect($collector->collectLayers($set, $definitions, $camera)[0]->cells)
        ->toBe([['column' => 1, 'row' => 1, 'source' => 0]]);
    $camera->moveTo(2, 0);
    expect($collector->collectLayers($set, $definitions, $camera)[0]->cells)
        ->toBe([['column' => 6, 'row' => 2, 'source' => 1]]);
})->with([false, true]);

it('retains wide-glyph fallback even when explicit artwork is authored at shifted logical cells', function () {
    $set = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', '#界##')]);
    $definitions = GraphicalTileDefinition::getForLayers(['layers' => ['terrain' => [
        'asset' => 'parts.png', 'cells' => array_map(static fn(int $x) => getCellArtworkOverride($x, 0), range(0, 3)),
    ]]], $set, 'inn');
    $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: $set->getComposedGrid());
    $batch = new GraphicalTileCollector()->collectLayers($set, $definitions, $camera)[0];
    expect($batch->cells)->toBe([['column' => 2, 'row' => 1, 'source' => 0]]);
});
