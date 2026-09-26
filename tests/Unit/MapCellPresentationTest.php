<?php

use Ichiloto\Engine\Field\MapCell;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;

it('normalizes every map cell to exactly two display columns, padding a narrow cell', function () {
  $row = NormalizedRow::fromCells(['a', '##', '界', "\e[31mb\e[0m"], MapCell::COLUMNS);
  expect($row->cells)->toHaveCount(8)
    ->and(array_slice($row->cells, 0, 4))->toBe(['a', ' ', '#', '#'])
    ->and($row->cells[4])->toBe('界')->and($row->cells[5])->toBe(NormalizedRow::CONTINUATION)
    ->and($row->cells[7])->toBe(' ')
    // Selecting cells selects whole, aligned column pairs.
    ->and(NormalizedRow::fromCells(['a', '##', '界'], MapCell::COLUMNS)->select(1, 1, MapCell::COLUMNS)->cells)->toBe(['#', '#'])
    ->and($row->select(2, 1, MapCell::COLUMNS)->cells)->toBe(['界', NormalizedRow::CONTINUATION])
    ->and($row->select(0, 1, MapCell::COLUMNS)->cells)->toBe(['a', ' '])
    ->and($row->select(1, 2, 4)->cells)->toBe(['#', '#', '界', NormalizedRow::CONTINUATION]);
});

it('uploads one wire cell per map cell with its two-column text and declared cell columns', function () {
  $set = new MapLayerSet([
    new MapLayer('ground', 0, false, 'ground', "##界\e[32m.\e[0m\e[33m,\e[0m  "),
    new MapLayer('fixtures', 1, false, 'fixtures', '      i '),
  ]);
  $world = PresentationWorld::getFromLayers($set);
  $put = $world->operations[0];
  $cells = $world->operations[1]['rows'][0]['cells'];
  $green = SgrColorParser::parse("\e[32m.\e[0m")['foreground']?->toArray();
  expect($put['value'])->toBe(['columns' => 4, 'rows' => 1, 'cellSize' => FieldViewport::CELL_SIZE,
      'cellColumns' => MapCell::COLUMNS, 'layers' => [
        ['id' => 'map:ground', 'layer' => -100, 'kind' => 'gameplay'],
        ['id' => 'map:fixtures', 'layer' => -99, 'kind' => 'gameplay'],
      ]])
    ->and(array_column($cells, 'glyph'))->toBe(['##', '界', '.,', 'i '])
    ->and(array_column($cells, 'ownerLayerId'))->toBe(['map:ground', 'map:ground', 'map:ground', 'map:fixtures'])
    ->and(array_keys($cells[0]))->toBe(['glyph', 'foreground', 'background', 'ownerLayerId'])
    // A styled pair takes the colours of its first visible character.
    ->and($cells[2]['foreground'])->not->toBeNull()->toBe($green);
});

it('keeps a blank map cell two columns wide on the wire', function () {
  $world = PresentationWorld::getFromLayers(new MapLayerSet([new MapLayer('ground', 0, false, 'ground', "  ..")]));
  expect(array_column($world->operations[1]['rows'][0]['cells'], 'glyph'))->toBe([MapCell::BLANK, '..']);
});
