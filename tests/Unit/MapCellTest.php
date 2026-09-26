<?php

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\Triggers\EventCue;
use Ichiloto\Engine\Field\MapCell;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\IO\Console\TerminalText;

final class MapCellEventAreaProbe extends MapManager
{
  public function __construct() {}

  /** @param array<int, string[]> $eventLayer */
  public function getEventAreas(array $eventLayer): array
  {
    return $this->extractEventAreas($eventLayer, 'town.event.php');
  }
}

/** @return array<string, CollisionType> */
function getMapCellCollisionTypes(): array
{
  return [' ' => CollisionType::NONE, '.' => CollisionType::NONE, '#' => CollisionType::SOLID,
    ';' => CollisionType::ENCOUNTER, '?' => CollisionType::SAVE_POINT, 'i' => CollisionType::PASS_THROUGH];
}

it('pairs one-column characters and keeps a two-column glyph as a whole cell', function () {
  expect(MapCell::COLUMNS)->toBe(2)
    ->and(MapCell::parseRow('##..[]'))->toBe(['##', '..', '[]'])
    ->and(MapCell::parseRow('🌲##界  '))->toBe(['🌲', '##', '界', '  '])
    ->and(MapCell::parseRow(''))->toBe([])
    ->and(MapCell::getCharacters('#.'))->toBe(['#', '.'])
    ->and(MapCell::getCharacters('🌲'))->toBe(['🌲']);
  foreach (MapCell::parseRow('🌲##界  ') as $cell) {
    expect(TerminalText::displayWidth($cell))->toBe(MapCell::COLUMNS);
  }
});

it('keeps each character of a styled pair in its own style', function () {
  $cells = MapCell::parseRow("\e[31m#\e[0m\e[32m.\e[0m\e[33m~~\e[0m");
  expect($cells)->toHaveCount(2)
    ->and(TerminalText::stripAnsi($cells[0]))->toBe('#.')
    ->and($cells[0])->toContain("\e[31m", "\e[32m")
    ->and(MapCell::getCharacters($cells[0]))->toBe(['#', '.'])
    ->and(TerminalText::stripAnsi($cells[1]))->toBe('~~');
});

it('refuses rows that do not divide into whole cells, naming the row and column', function (string $text, string $message) {
  expect(fn() => MapLayer::parseGrid($text, 'town/layers/01.floor.map.php'))
    ->toThrow(InvalidArgumentException::class, $message);
})->with([
  'a two-column glyph after a lone character' => ["####\n#🌲#", 'town/layers/01.floor.map.php row 1, column 1: a two-column glyph must begin a cell'],
  'a lone trailing character' => ["##\n###", 'town/layers/01.floor.map.php row 1 ends halfway through a cell'],
]);

it('treats a cell of spaces as blank and builds blank rows', function () {
  expect(MapCell::isBlank(MapCell::BLANK))->toBeTrue()
    ->and(MapCell::isBlank("\e[44m  \e[0m"))->toBeTrue()
    ->and(MapCell::isBlank(' x'))->toBeFalse()
    ->and(MapCell::getBlankRow(3))->toBe(['  ', '  ', '  '])
    ->and(MapCell::getBlankRow(-1))->toBe([]);
});

it('reads one event marker from either or both characters of a cell', function () {
  expect(MapCell::getMarker('E '))->toBe('E')
    ->and(MapCell::getMarker(' E'))->toBe('E')
    ->and(MapCell::getMarker('EE'))->toBe('E')
    ->and(MapCell::getMarker("\e[31mE\e[0m "))->toBe('E')
    ->and(MapCell::getMarker('🚪'))->toBe('🚪')
    ->and(MapCell::getMarker('  '))->toBeNull()
    ->and(fn() => MapCell::getMarker('EF', 'Event cell at row 2, column 3'))
    ->toThrow(InvalidArgumentException::class, 'Event cell at row 2, column 3 holds two different markers, E and F.');
});

it('counts the cells a run of terminal text covers from a cell', function (int $columns, int $cells) {
  expect(MapCell::getSpanCells($columns))->toBe($cells);
})->with([[0, 1], [1, 1], [2, 1], [3, 2], [4, 2], [5, 3]]);

it('resolves a cell as solid when any character is solid, so pairing never opens a wall', function (string $cell) {
  expect(MapCollisionResolver::resolveCell($cell, getMapCellCollisionTypes()))->toBe(CollisionType::SOLID);
})->with(['#.', '.#', ';#', '#i', 'x.', '.x']);

it('resolves a non-solid cell to its first kind other than none, left to right', function (string $cell, CollisionType $expected) {
  expect(MapCollisionResolver::resolveCell($cell, getMapCellCollisionTypes()))->toBe($expected);
})->with([
  'none then encounter' => ['.;', CollisionType::ENCOUNTER],
  'encounter then save point' => [';?', CollisionType::ENCOUNTER],
  'save point then encounter' => ['?;', CollisionType::SAVE_POINT],
  'both none' => ['..', CollisionType::NONE],
  'pass-through beside none' => ['i.', CollisionType::NONE],
  'pass-through beside encounter' => ['i;', CollisionType::ENCOUNTER],
  'only pass-through' => ['ii', CollisionType::PASS_THROUGH],
  'styled characters' => ["\e[32m.\e[0m\e[33m;\e[0m", CollisionType::ENCOUNTER],
]);

it('ignores spaces inside an occupied cell and gives a blank cell the space entry', function () {
  $types = [' ' => CollisionType::SOLID, ';' => CollisionType::ENCOUNTER, 'i' => CollisionType::PASS_THROUGH];
  expect(MapCollisionResolver::resolveCell('; ', $types))->toBe(CollisionType::ENCOUNTER)
    ->and(MapCollisionResolver::resolveCell(' ;', $types))->toBe(CollisionType::ENCOUNTER)
    ->and(MapCollisionResolver::resolveCell('i ', $types))->toBe(CollisionType::PASS_THROUGH)
    ->and(MapCollisionResolver::resolveCell('  ', $types))->toBe(CollisionType::SOLID)
    ->and(MapCollisionResolver::resolveCell('  ', [' ' => CollisionType::NONE]))->toBe(CollisionType::NONE)
    ->and(MapCollisionResolver::resolveCell('  ', []))->toBe(CollisionType::SOLID);
});

it('lets an upper pass-through cell defer to the layers below while a mixed cell decides', function () {
  $set = new MapLayerSet([
    new MapLayer('ground', 1, false, 'ground', ';;..##..'),
    new MapLayer('fixtures', 2, false, 'fixtures', 'i  ii i#'),
  ]);
  expect(MapCollisionResolver::resolveLayers($set, getMapCellCollisionTypes()))->toBe([[
    CollisionType::ENCOUNTER->value, CollisionType::NONE->value, CollisionType::SOLID->value, CollisionType::SOLID->value,
  ]]);
});

it('generates the same per-cell collision from authored rows as the layer resolver', function () {
  $text = "#.;.  i ??\n..#;i;  ##";
  $manager = new ReflectionClass(MapManager::class)->newInstanceWithoutConstructor();
  $set = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', $text)]);
  $expected = [
    [CollisionType::SOLID->value, CollisionType::ENCOUNTER->value, CollisionType::NONE->value,
      CollisionType::SOLID->value, CollisionType::SAVE_POINT->value],
    [CollisionType::NONE->value, CollisionType::SOLID->value, CollisionType::ENCOUNTER->value,
      CollisionType::NONE->value, CollisionType::SOLID->value],
  ];
  // A lone pass-through cell on a single layer has nothing below it and stays solid.
  expect($manager->generateCollisionMap(explode("\n", $text), getMapCellCollisionTypes()))->toBe($expected)
    ->and(MapCollisionResolver::resolveLayers($set, getMapCellCollisionTypes()))->toBe($expected);
});

it('reads event areas from paired markers and whole-glyph markers', function () {
  $layer = MapLayer::parseGrid("  E EE    \n  EEE   🚪\n          ");
  expect(new MapCellEventAreaProbe()->getEventAreas($layer))->toBe([
    'E' => ['x' => 1, 'y' => 0, 'width' => 2, 'height' => 2],
    '🚪' => ['x' => 4, 'y' => 1, 'width' => 1, 'height' => 1],
  ]);
});

it('refuses an event cell holding two different markers with its location', function () {
  $layer = MapLayer::parseGrid("  EF\n    ");
  expect(fn() => new MapCellEventAreaProbe()->getEventAreas($layer))->toThrow(InvalidArgumentException::class,
    'Event cell at row 0, column 1 of town.event.php holds two different markers, E and F.');
});

it('accepts event cue symbols up to one cell wide and refuses wider or multiple symbols', function () {
  expect(new EventCue('!')->symbol)->toBe('!')
    ->and(new EventCue('🚪')->symbol)->toBe('🚪')
    ->and(fn() => new EventCue('!!'))->toThrow(InvalidArgumentException::class, 'fits one map cell');
});
