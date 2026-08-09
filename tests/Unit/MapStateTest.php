<?php

use Ichiloto\Engine\Field\RegionMap;
use Ichiloto\Engine\Scenes\Game\States\MapState;

/**
 * Draws the region map without a running game: where the party has been and
 * where they are standing are the only things the drawing needs from one.
 */
class RegionMapDrawingProbe extends MapState
{
  /**
   * @param string $currentId The map the player is on.
   * @param string[] $visited The maps the party has been to.
   */
  public function __construct(private string $currentId, private array $visited)
  {
  }

  public function draw(int $width = 108, int $height = 26): array
  {
    return $this->buildMapContent($width, $height);
  }

  protected function currentMapId(): string
  {
    return $this->currentId;
  }

  protected function hasVisited(string $id): bool
  {
    return in_array($id, $this->visited, true);
  }
}

/**
 * Finds where a label was drawn.
 *
 * @param string[] $rows The drawn rows.
 * @param string $label The text to find.
 * @return array{row: int, column: int}|null Where it is, or null.
 */
function findOnMap(array $rows, string $label): ?array
{
  foreach ($rows as $row => $line) {
    $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $line);
    $column = mb_strpos($plain, $label);

    if ($column !== false) {
      // Names are centred in their cells, so the middle of the name is what
      // says which cell it is in.
      return ['row' => $row, 'column' => $column + intdiv(mb_strlen($label), 2)];
    }
  }

  return null;
}

beforeEach(function () {
  // The demo's town: the house is up on the north-west side of the square, the
  // shop straight down at the south, and the inn off the south-east corner
  // with its rooms behind it.
  writeTestMaps([
    'happyville/town-center' => [
      'name' => 'Town Center',
      'region' => 'Happyville',
      'to' => [
        'happyville/home' => [2, 1],
        'happyville/shop' => [10, 8],
        'happyville/inn-front' => [18, 8],
      ],
    ],
    'happyville/home' => ['name' => 'Home', 'region' => 'Happyville', 'to' => ['happyville/town-center' => [10, 9]]],
    'happyville/shop' => ['name' => 'Shop', 'region' => 'Happyville', 'to' => ['happyville/town-center' => [10, 0]]],
    'happyville/inn-front' => [
      'name' => 'Inn',
      'region' => 'Happyville',
      'to' => ['happyville/town-center' => [1, 0], 'happyville/inn-back' => [10, 0]],
    ],
    'happyville/inn-back' => ['name' => 'Inn Rooms', 'region' => 'Happyville', 'to' => ['happyville/inn-front' => [10, 9]]],
  ]);
});

afterEach(function () {
  RegionMap::reset();
});

it('draws each place where it actually is', function () {
  $rows = new RegionMapDrawingProbe(
    'happyville/town-center',
    ['happyville/town-center', 'happyville/home', 'happyville/shop', 'happyville/inn-front']
  )->draw();

  $town = findOnMap($rows, 'Town Center');
  $home = findOnMap($rows, 'Home');
  $shop = findOnMap($rows, 'Shop');
  $inn = findOnMap($rows, 'Inn');

  expect($town)->not->toBeNull()
    // The house is up and to the left, the shop straight down, the inn down
    // and to the right, which is where they are in the world.
    ->and($home['row'])->toBeLessThan($town['row'])
    ->and($home['column'])->toBeLessThan($town['column'])
    ->and($shop['row'])->toBeGreaterThan($town['row'])
    ->and(abs($shop['column'] - $town['column']))->toBeLessThanOrEqual(1)
    ->and($inn['row'])->toBeGreaterThan($town['row'])
    ->and($inn['column'])->toBeGreaterThan($town['column']);
});

it('names only the places the party has been to', function () {
  $drawing = implode("\n", new RegionMapDrawingProbe(
    'happyville/town-center',
    ['happyville/town-center']
  )->draw());

  // The doors out of the town centre are visible from it, but where they go is
  // not known until they are opened.
  expect($drawing)->toContain('Town Center')
    ->and($drawing)->not->toContain('Shop')
    ->and(substr_count($drawing, '?????'))->toBe(3);
});

it('leaves out what the party has no way of knowing about', function () {
  $drawing = implode("\n", new RegionMapDrawingProbe(
    'happyville/town-center',
    ['happyville/town-center']
  )->draw());

  // The inn's rooms are two doors away, behind a door never opened.
  expect($drawing)->not->toContain('Inn Rooms')
    ->and(substr_count($drawing, '?????'))->toBe(3);
});

it('draws the doors between the places', function () {
  $drawing = implode("\n", new RegionMapDrawingProbe(
    'happyville/town-center',
    ['happyville/town-center', 'happyville/home', 'happyville/shop', 'happyville/inn-front']
  )->draw());

  expect($drawing)->toContain('─')
    ->and($drawing)->toContain('│');
});

it('draws a compass so the layout reads as a map', function () {
  $rows = new RegionMapDrawingProbe('happyville/town-center', ['happyville/town-center'])->draw();

  expect($rows[0])->toContain('N')
    ->and($rows[1])->toContain('W')
    ->and($rows[1])->toContain('E')
    ->and($rows[2])->toContain('S');
});

it('says so plainly when the player is somewhere off the map', function () {
  $rows = new RegionMapDrawingProbe('atlantis/deep', [])->draw();

  expect($rows[0])->toContain('not on any map');
});

it('draws nothing wider than the panel it was given', function () {
  $rows = new RegionMapDrawingProbe(
    'happyville/town-center',
    ['happyville/town-center', 'happyville/home', 'happyville/shop', 'happyville/inn-front', 'happyville/inn-back']
  )->draw(60, 12);

  expect($rows)->toHaveCount(12);

  foreach ($rows as $row) {
    // The row the player is on carries colour codes, which take no columns.
    $visible = preg_replace('/\x1b\[[0-9;]*m/', '', $row);

    expect(mb_strlen($visible))->toBeLessThanOrEqual(60);
  }
});
