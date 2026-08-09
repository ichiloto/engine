<?php

use Ichiloto\Engine\Field\RegionMap;
use Ichiloto\Engine\Scenes\Game\States\MapState;

/**
 * Draws the region map without a running game: what the party has been to and
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

beforeEach(function () {
  // The demo's shape: a hub with a house, a shop, and an inn with a back room.
  writeTestMaps([
    'happyville/town-center' => ['name' => 'Town Center', 'region' => 'Happyville', 'to' => ['happyville/home', 'happyville/shop', 'happyville/inn-front']],
    'happyville/home' => ['name' => 'Home', 'region' => 'Happyville', 'to' => ['happyville/town-center']],
    'happyville/shop' => ['name' => 'Shop', 'region' => 'Happyville', 'to' => ['happyville/town-center']],
    'happyville/inn-front' => ['name' => 'Inn', 'region' => 'Happyville', 'to' => ['happyville/town-center', 'happyville/inn-back']],
    'happyville/inn-back' => ['name' => 'Inn Rooms', 'region' => 'Happyville', 'to' => ['happyville/inn-front']],
  ]);
});

afterEach(function () {
  RegionMap::reset();
});

it('draws the places around the player and how they connect', function () {
  $rows = new RegionMapDrawingProbe(
    'happyville/town-center',
    ['happyville/town-center', 'happyville/home']
  )->draw();

  $drawing = implode("\n", $rows);

  expect($drawing)->toContain('Town Center')
    ->and($drawing)->toContain('Home')
    // Two doors from here, and never opened: not on the map yet.
    ->and($drawing)->not->toContain('Inn Rooms');
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

it('merges the doors leaving one place into a single branch', function () {
  $rows = new RegionMapDrawingProbe(
    'happyville/town-center',
    ['happyville/town-center', 'happyville/home', 'happyville/shop', 'happyville/inn-front']
  )->draw();

  $drawing = implode("\n", $rows);

  // Three doors from the same place share one column, so the run through it
  // branches rather than being overwritten by each in turn.
  expect($drawing)->toContain('┬')
    ->and($drawing)->toContain('├')
    ->and($drawing)->toContain('└');
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
