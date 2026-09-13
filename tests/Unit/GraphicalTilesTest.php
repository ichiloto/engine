<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Tiles\GraphicalTileCollector;
use Ichiloto\Engine\Rendering\Tiles\GraphicalTileDefinition;

function terrainData(array $symbols = []): array
{
  return ['asset' => 'Graphics/Tilesets/Field.png', 'symbols' => $symbols ?: [
    ';' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 32],
    '~' => ['x' => 16, 'y' => 0, 'width' => 16, 'height' => 32],
  ]];
}

/** @return array<string, array<int, array<int, string>>> */
function terrainTextCells(ConsolePresentationSnapshot $snapshot): array
{
  $cells = [];
  foreach ($snapshot->textLayers as $layer) {
    foreach ($layer->runs as $run) {
      foreach (mb_str_split($run->text) as $offset => $symbol) {
        $cells[$layer->id][$run->row][$run->column + $offset] = $symbol;
      }
    }
  }
  return $cells;
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
  foreach ($this->consoleState as $name => $value) { new ReflectionProperty(Console::class, $name)->setValue(null, $value); }
});

it('normalizes explicit styled space numeric and narrow unicode symbols and deduplicates sources', function () {
  $rect = terrainData()['symbols'][';'];
  $definition = GraphicalTileDefinition::fromArray(terrainData(["\e[32m;\e[0m" => $rect,
    ' ' => $rect, 1 => $rect, "é" => $rect]), 'garden');
  expect($definition->symbols)->toBe([';' => 0, ' ' => 0, 1 => 0, 'é' => 0])
    ->and($definition->sources)->toHaveCount(1);
});

it('rejects present malformed terrain with map context', function ($data) {
  expect(fn() => GraphicalTileDefinition::fromArray($data, 'Garden.data.php'))
    ->toThrow(InvalidArgumentException::class, 'Garden.data.php tiles2d:');
})->with([
  'null' => [null], 'empty' => [[]], 'unknown' => [terrainData() + ['layer' => -100]],
  'absolute' => [array_replace(terrainData(), ['asset' => '/tmp/a.png'])],
  'traversal' => [array_replace(terrainData(), ['asset' => '../a.png'])],
  'non png' => [array_replace(terrainData(), ['asset' => 'a.jpg'])],
  'multi symbol' => [terrainData(['ab' => ['x'=>0,'y'=>0,'width'=>1,'height'=>1]])],
  'wide' => [terrainData(['界' => ['x'=>0,'y'=>0,'width'=>1,'height'=>1]])],
  'combining' => [terrainData(["\u{301}" => ['x'=>0,'y'=>0,'width'=>1,'height'=>1]])],
  'control' => [terrainData(["\n" => ['x'=>0,'y'=>0,'width'=>1,'height'=>1]])],
  'zero' => [terrainData([';' => ['x'=>0,'y'=>0,'width'=>0,'height'=>1]])],
  'overflow' => [terrainData([';' => ['x'=>4294967295,'y'=>0,'width'=>1,'height'=>1]])],
  'numeric string' => [terrainData([';' => ['x'=>'0','y'=>0,'width'=>1,'height'=>1]])],
  'unknown rect' => [terrainData([';' => ['x'=>0,'y'=>0,'width'=>1,'height'=>1,'extra'=>1]])],
  'normalized duplicate' => [terrainData([';' => ['x'=>0,'y'=>0,'width'=>1,'height'=>1],
    "\e[32m;" => ['x'=>0,'y'=>0,'width'=>1,'height'=>1]])],
]);

it('uses shared camera centering and only emits in-bounds authored mapped cells', function () {
  $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: [TerminalText::visibleSymbols("\e[32m;\e[0m ~")]);
  $collector = new GraphicalTileCollector();
  expect($collector->collect(null, $camera))->toBe([]);
  $batch = $collector->collect(GraphicalTileDefinition::fromArray(terrainData(), 'garden'), $camera)[0];
  expect($batch->cells)->toBe([['column'=>2,'row'=>1,'source'=>0], ['column'=>4,'row'=>1,'source'=>1]])
    ->and($batch->layer)->toBe(-100);
  $camera->worldSpace = [str_split(';'), str_split(';;;')];
  $batch = $collector->collect(GraphicalTileDefinition::fromArray(terrainData(), 'garden'), $camera)[0];
  expect($batch->cells)->toHaveCount(4); // No padding cells on the short first row.
});

it('bounds collection through pans and edges without scanning offscreen rows', function () {
  $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: array_fill(0, 12, array_fill(0, 16, ';')));
  $definition = GraphicalTileDefinition::fromArray(terrainData(), 'garden');
  foreach ([[0,0], [2,3], [100,100], [-1,-1]] as [$x,$y]) {
    $camera->moveTo($x,$y);
    $batch = new GraphicalTileCollector()->collect($definition, $camera)[0];
    expect($batch->cells)->toHaveCount(32)->and($batch->cells[0])->toBe(['column'=>0,'row'=>0,'source'=>0])
      ->and($batch->cells[31])->toBe(['column'=>7,'row'=>3,'source'=>0]);
  }
  // If the collector touches this offscreen row's value it cannot normalize it.
  $camera->worldSpace = [str_split(';;;;'), [new stdClass()]];
  $camera->resizeViewport(4,1);
  expect(new GraphicalTileCollector()->collect($definition, $camera)[0]->cells)->toHaveCount(4);
});

it('preserves unmapped wide content and never removes its shifted text cells', function () {
  $camera = new Camera(makeCameraTestScene(), 8, 4, worldSpace: [TerminalText::visibleSymbols(';界;é')]);
  expect(new GraphicalTileCollector()->collect(GraphicalTileDefinition::fromArray(terrainData(), 'garden'), $camera)[0]->cells)
    ->toBe([['column'=>2,'row'=>1,'source'=>0]]);
});

it('bounds collection at the tile budget even for maximum protocol geometry', function () {
  $camera = new Camera(makeCameraTestScene(), 512, 256,
    worldSpace: array_fill(0, 256, array_fill(0, 512, ';')));
  $batch = new GraphicalTileCollector()->collect(GraphicalTileDefinition::fromArray(terrainData(), 'large-field'), $camera)[0];
  expect($batch->cells)->toHaveCount(\Ichiloto\Engine\Rendering\Presentation\PresentationTileBatch::MAX_CELLS)
    ->and($batch->cells[0])->toBe(['column' => 0, 'row' => 0, 'source' => 0])
    ->and($batch->cells[array_key_last($batch->cells)])->toBe(['column' => 511, 'row' => 63, 'source' => 0]);
  $batch->assertWithin(new \Ichiloto\Engine\Rendering\Transport\RendererGridConfig(512, 256));
});

it('removes only replaced terrain and its blank underlay while preserving all later content', function ($overlay) {
  Console::recomposeFrame(function () use ($overlay) {
    PresentationLayerPolicy::terrain(fn() => Console::write(';;;;~', 0, 1));
    Console::write($overlay, 1, 1);
    Console::withLayer('player', fn() => Console::write('@', 2, 1));
    Console::withLayer('hud', fn() => Console::write(' ', 3, 1), 1010);
  });
  $before = Console::snapshot();
  $cells = terrainTextCells(Console::presentationSnapshot(['player'], ['terrain' => [1 => array_fill(0, 4, true)]]));
  expect($cells['world'][1])->not->toHaveKeys([0,2,3])
    ->and($cells['world'][1][1])->toBe($overlay)
    ->and($cells['terrain'][1])->toBe([4 => '~'])
    ->and($cells['hud'][1][3])->toBe(' ')
    ->and(Console::snapshot())->toEqual($before)
    ->and(Console::snapshot(['player'])->rows[1])->toBe(';' . $overlay . '; ~   ');
})->with([';', ' ', 'N', '!']);

it('restores background provenance without retaining old sprites and rolls failed recomposition back', function () {
  $mask = ['terrain' => [1 => [1 => true]]];
  PresentationLayerPolicy::terrain(fn() => Console::write(';', 1, 1));
  Console::withLayer('player', fn() => Console::write('@', 1, 1));
  PresentationLayerPolicy::terrain(fn() => Console::write(';', 1, 1));
  expect(terrainTextCells(Console::presentationSnapshot([], $mask))['world'][1])->not->toHaveKey(1);
  $before = Console::presentationSnapshot([], $mask);
  expect(fn() => Console::recomposeFrame(function () {
    PresentationLayerPolicy::terrain(fn() => Console::write('~', 1, 1));
    throw new RuntimeException('rollback');
  }))->toThrow(RuntimeException::class);
  expect(Console::presentationSnapshot([], $mask))->toEqual($before);
  Console::recomposeFrame(fn() => Console::write('menu', 0, 0));
  expect(Console::presentationSnapshot([], $mask))->toEqual(Console::presentationSnapshot());
});

it('preserves tile replacement provenance while a retained notification covers the player', function () {
  Console::recomposeFrame(function () {
    PresentationLayerPolicy::terrain(fn() => Console::write(';;;;', 0, 1));
    Console::withLayer('player', fn() => Console::write('@', 1, 1));
  });
  $mask = ['terrain' => [1 => array_fill(0, 4, true)]];
  $before = Console::presentationSnapshot(['player'], $mask);
  Console::replaceOverlay('notice', [' '], 1, 1, 2000);
  $cells = terrainTextCells(Console::presentationSnapshot(['player'], $mask));
  expect($cells['notice'][1][1])->toBe(' ')
    ->and($cells['world'][1])->not->toHaveKeys([0, 1, 2, 3])
    ->and(Console::snapshot()->rows[1])->toStartWith('; ;;');
  Console::removeOverlay('notice');
  expect(Console::presentationSnapshot(['player'], $mask))->toEqual($before)
    ->and(Console::snapshot()->rows[1])->toStartWith(';@;;');
});
