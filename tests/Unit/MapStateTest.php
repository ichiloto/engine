<?php

use Ichiloto\Engine\Scenes\Game\States\MapState;

/**
 * Runs the real sampling against a tile map, without a running game.
 */
class MapSamplingProbe extends MapState
{
  public function __construct()
  {
    // The sampler needs no scene.
  }

  public function sample(array $tileMap, int $x, int $y, int $horizontalStep, int $verticalStep): string
  {
    return $this->sampleTile($tileMap, $x, $y, $horizontalStep, $verticalStep);
  }
}

/**
 * Splits rows of text into the character grid a tile map is.
 *
 * @param string[] $rows The map rows.
 * @return array<int, string[]> The tile map.
 */
function tileMapFrom(array $rows): array
{
  return array_map(static fn(string $row): array => mb_str_split($row), $rows);
}

it('keeps a thin wall that a sampled block would otherwise lose', function () {
  $tileMap = tileMapFrom([
    '    ',
    ' |  ',
    '    ',
    '    ',
  ]);

  // A 2x2 block holding one wall character reads as wall, not as floor: a
  // corridor sampled away would read as an open room.
  expect(new MapSamplingProbe()->sample($tileMap, 0, 0, 2, 2))->toBe('|');
});

it('reads an empty block as empty', function () {
  $tileMap = tileMapFrom(['    ', '    ']);

  expect(new MapSamplingProbe()->sample($tileMap, 0, 0, 2, 2))->toBe(' ');
});

it('samples past the edge of the map without failing', function () {
  $tileMap = tileMapFrom(['##', '##']);

  expect(new MapSamplingProbe()->sample($tileMap, 4, 4, 2, 2))->toBe(' ');
});

it('takes a single column from a wide tile', function () {
  $tileMap = tileMapFrom(['🌲.', '..']);

  // Sprites in the tile layer can be wider than a cell; the map is a grid, so
  // one column is all a sampled block may contribute.
  expect(mb_strlen(new MapSamplingProbe()->sample($tileMap, 0, 0, 1, 1)))->toBe(1);
});
