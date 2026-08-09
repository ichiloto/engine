<?php

use Ichiloto\Engine\Field\RegionArea;
use Ichiloto\Engine\Field\RegionMap;

/**
 * The demo's shape: a hub town with a house, a shop, an inn with a back room,
 * and a road out to another region.
 */
function writeHappyvilleMaps(): void
{
  writeTestMaps([
    'happyville/town-center' => ['name' => 'Town Center', 'region' => 'Happyville', 'to' => ['happyville/home', 'happyville/shop', 'happyville/inn-front', 'overworld']],
    'happyville/home' => ['name' => 'Home', 'region' => 'Happyville', 'to' => ['happyville/town-center']],
    'happyville/shop' => ['name' => 'Shop', 'region' => 'Happyville', 'to' => ['happyville/town-center']],
    'happyville/inn-front' => ['name' => 'Inn', 'region' => 'Happyville', 'to' => ['happyville/town-center', 'happyville/inn-back']],
    'happyville/inn-back' => ['name' => 'Inn Rooms', 'region' => 'Happyville', 'to' => ['happyville/inn-front']],
    'overworld' => ['name' => 'Overworld', 'region' => 'Overworld', 'to' => ['happyville/town-center']],
  ]);
}

afterEach(function () {
  RegionMap::reset();
});

it('reads each map name, region, and where its doors lead', function () {
  writeHappyvilleMaps();

  $areas = RegionMap::areas();

  expect($areas)->toHaveCount(6)
    ->and($areas['happyville/home'])->toBeInstanceOf(RegionArea::class)
    ->and($areas['happyville/home']->name)->toBe('Home')
    ->and($areas['happyville/home']->region)->toBe('Happyville')
    ->and($areas['happyville/town-center']->links)
    ->toBe(['happyville/home', 'happyville/shop', 'happyville/inn-front', 'overworld']);
});

it('groups a region and leaves other regions out of it', function () {
  writeHappyvilleMaps();

  expect(array_keys(RegionMap::inRegion('Happyville')))->toHaveCount(5)
    ->and(RegionMap::inRegion('Happyville'))->not->toHaveKey('overworld')
    // Region names come from map data, so they are matched the way an author
    // would expect rather than byte for byte.
    ->and(RegionMap::inRegion('happyville'))->toHaveCount(5);
});

it('lays the region out by how many doors away each place is', function () {
  writeHappyvilleMaps();

  $columns = RegionMap::layout('happyville/home');

  expect($columns[0])->toBe(['happyville/home'])
    ->and($columns[1])->toBe(['happyville/town-center'])
    ->and($columns[2])->toBe(['happyville/shop', 'happyville/inn-front'])
    ->and($columns[3])->toBe(['happyville/inn-back']);
});

it('lays the same region out differently from somewhere else in it', function () {
  writeHappyvilleMaps();

  $columns = RegionMap::layout('happyville/town-center');

  // The map answers "what is next door to me", so it is drawn from where the
  // player is standing.
  expect($columns[0])->toBe(['happyville/town-center'])
    ->and($columns[1])->toBe(['happyville/home', 'happyville/shop', 'happyville/inn-front']);
});

it('still places a room no door reaches from here', function () {
  writeTestMaps([
    'crypt/entrance' => ['name' => 'Entrance', 'region' => 'Crypt', 'to' => ['crypt/hall']],
    'crypt/hall' => ['name' => 'Hall', 'region' => 'Crypt', 'to' => ['crypt/entrance']],
    'crypt/vault' => ['name' => 'Sealed Vault', 'region' => 'Crypt'],
  ]);

  $columns = RegionMap::layout('crypt/entrance');

  // A map only a cutscene can reach is still part of the region.
  expect(array_merge(...$columns))->toContain('crypt/vault');
});

it('reports the regions a region leads out to, once each', function () {
  writeHappyvilleMaps();

  $exits = RegionMap::exits('Happyville');

  expect($exits)->toHaveCount(1)
    ->and($exits[0]->region)->toBe('Overworld')
    ->and($exits[0]->name)->toBe('Overworld');
});

it('has nothing to lay out for a map it has never heard of', function () {
  writeHappyvilleMaps();

  expect(RegionMap::layout('atlantis/deep'))->toBe([]);
});

it('reads no maps at all when a project has none', function () {
  RegionMap::loadFrom(sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ichiloto-empty-', true));

  expect(RegionMap::areas())->toBe([]);
});
