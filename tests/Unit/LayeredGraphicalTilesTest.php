<?php

use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Tiles\GraphicalTileCollector;
use Ichiloto\Engine\Rendering\Tiles\GraphicalTileDefinition;

function getLayerCrop(int $x = 0): array
{
    return ['x' => $x, 'y' => 0, 'width' => 16, 'height' => 16];
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

it('resolves per-layer glyph namespaces with shared or separate atlases', function () {
    $set = new MapLayerSet([
        new MapLayer('terrain', 1, false, 'terrain', 'xx'),
        new MapLayer('buildings', 10, false, 'buildings', ' x'),
        new MapLayer('rug', 20, true, 'rug', 'r '),
    ]);
    $definitions = GraphicalTileDefinition::getForLayers(['asset' => 'shared.png', 'layers' => [
        'terrain' => ['symbols' => ['x' => getLayerCrop()]],
        'buildings' => ['symbols' => ['x' => getLayerCrop(16), ' ' => getLayerCrop(32)]],
        'rug' => ['asset' => 'rugs.png', 'symbols' => ['r' => getLayerCrop()]],
    ]], $set, 'map.data.php');
    GraphicalTileDefinition::validateDecoration($set, $definitions);
    $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: $set->getComposedGrid());
    $batches = new GraphicalTileCollector()->collectLayers($set, $definitions, $camera);
    expect(array_column($batches, 'id'))->toBe(['map:terrain', 'map:buildings', 'map:rug'])
        ->and(array_column($batches, 'layer'))->toBe([-99, -90, -80])
        ->and(array_column($batches, 'asset'))->toBe(['shared.png', 'shared.png', 'rugs.png'])
        ->and($batches[0]->cells)->toHaveCount(2)
        ->and($batches[1]->cells)->toBe([['column' => 4, 'row' => 1, 'source' => 0]])
        ->and($batches[1]->sources[0]->x)->toBe(16)
        ->and($batches[2]->cells)->toBe([['column' => 3, 'row' => 1, 'source' => 0]]);
});

it('refuses missing decoration crops with the authored file and exact glyph location', function () {
    $set = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', ' '),
        new MapLayer('rugs', 2, true, 'town/layers/02.rugs.deco.php', 'r')]);
    expect(fn() => GraphicalTileDefinition::validateDecoration($set, []))
        ->toThrow(InvalidArgumentException::class, "town/layers/02.rugs.deco.php: glyph 'r' at row 0, column 0");
    expect(fn() => GraphicalTileDefinition::getForLayers(['asset' => 'shared.png', 'layers' => [
        'typo' => ['symbols' => ['r' => getLayerCrop()]],
    ]], $set, 'town.data.php'))->toThrow(InvalidArgumentException::class, 'unknown or malformed layer typo');
});

it('keeps terminal composition exact while unmapped gameplay glyphs and decorative crops retain their stacking positions', function () {
    $set = new MapLayerSet([
        new MapLayer('terrain', 1, false, 'terrain', 'xxxx'),
        new MapLayer('fixtures', 2, false, 'fixtures', " \e[33mi\e[0m  "),
        new MapLayer('deco', 3, true, 'deco', '  r '),
    ]);
    $definitions = GraphicalTileDefinition::getForLayers(['asset' => 'shared.png', 'layers' => [
        'terrain' => ['symbols' => ['x' => getLayerCrop()]],
        'deco' => ['symbols' => ['r' => getLayerCrop(16)]],
    ]], $set, 'town.data.php');
    $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: $set->getComposedGrid());
    $camera->renderMap();
    $expected = Console::snapshot();
    Console::recomposeFrame(fn() => $camera->renderMap($set));
    expect(Console::snapshot())->toEqual($expected);
    $batches = new GraphicalTileCollector()->collectLayers($set, $definitions, $camera);
    $mask = [];
    foreach ($batches as $batch) {
        foreach ($batch->cells as $cell) {
            $mask[$batch->id][$cell['row']][$cell['column']] = true;
        }
    }
    $snapshot = Console::presentationSnapshot([], $mask);
    $layers = array_column($snapshot->textLayers, null, 'id');
    expect($layers)->not->toHaveKey('map:terrain')
        ->and($layers['map:fixtures']->layer)->toBe(-98)
        ->and($layers['map:fixtures']->runs[0]->text)->toBe('i')
        ->and($layers['map:fixtures']->runs[0]->foreground)->not->toBeNull()
        ->and(Console::snapshot())->toEqual($expected);
    // No synthetic WORLD-plane space may cover the lower map layers.
    $worldColumns = [];
    foreach ($layers['world']->runs as $run) {
        if ($run->row === 1) {
            foreach (mb_str_split($run->text) as $offset => $_) { $worldColumns[] = $run->column + $offset; }
        }
    }
    expect($worldColumns)->not->toContain(2, 3, 4, 5);
    Console::withLayer('player', fn() => Console::write('@', 3, 1));
    PresentationLayerPolicy::drawMapLayer($set->getGameplayLayerAt(1, 0), fn() => $camera->renderBackgroundTile(1, 0));
    expect(Console::snapshot())->toEqual($expected);
});

it('preserves camera clipping centering ragged rows and wide-glyph fallback across layered draws', function (string $text) {
    $set = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', $text)]);
    $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: $set->getComposedGrid());
    foreach ([[0, 0], [2, 0]] as [$x, $y]) {
        $camera->moveTo($x, $y);
        Console::recomposeFrame($camera->renderMap(...));
        $expected = Console::snapshot();
        Console::recomposeFrame(fn() => $camera->renderMap($set));
        expect(Console::snapshot())->toEqual($expected);
    }
})->with(['xx', "x\nxxx", 'xx界xx', 'xxxxxxxx界x', ";e\e[0m\u{0301}界;a"]);

it('does not map any layer onto cells shifted by a composed wide glyph', function () {
    $set = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', 'xxxx'),
        new MapLayer('fixtures', 2, false, 'fixtures', ' 界  ')]);
    $definitions = GraphicalTileDefinition::getForLayers(['asset' => 'shared.png', 'layers' => [
        'terrain' => ['symbols' => ['x' => getLayerCrop()]],
    ]], $set, 'town.data.php');
    $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: $set->getComposedGrid());
    $batches = new GraphicalTileCollector()->collectLayers($set, $definitions, $camera);
    expect($batches[0]->cells)->toBe([['column' => 2, 'row' => 1, 'source' => 0]]);
});
