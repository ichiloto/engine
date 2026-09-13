<?php

use Ichiloto\Engine\Field\Enumerations\CompassDirection;
use Ichiloto\Engine\Field\RegionArea;
use Ichiloto\Engine\Field\RegionMap;

/**
 * The demo's town: the house is up on the north-west side of the square, the
 * shop is straight down at the south, the inn is off the south-east corner
 * with its rooms behind it, and the road out is east.
 */
function writeHappyvilleMaps(): void
{
  writeTestMaps([
    'happyville/town-center' => [
      'name' => 'Town Center',
      'region' => 'Happyville',
      'to' => [
        'happyville/home' => [2, 1],
        'happyville/shop' => [10, 8],
        'happyville/inn-front' => [18, 8],
        'overworld' => [19, 5],
      ],
    ],
    'happyville/home' => ['name' => 'Home', 'region' => 'Happyville', 'to' => ['happyville/town-center' => [10, 9]]],
    'happyville/shop' => ['name' => 'Shop', 'region' => 'Happyville', 'to' => ['happyville/town-center' => [10, 9]]],
    'happyville/inn-front' => [
      'name' => 'Inn',
      'region' => 'Happyville',
      'to' => ['happyville/town-center' => [1, 9], 'happyville/inn-back' => [10, 0]],
    ],
    'happyville/inn-back' => ['name' => 'Inn Rooms', 'region' => 'Happyville', 'to' => ['happyville/inn-front' => [10, 9]]],
    'overworld' => ['name' => 'Overworld', 'region' => 'Overworld', 'to' => ['happyville/town-center' => [1, 5]]],
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
    ->and($areas['happyville/town-center']->destinations())
    ->toBe(['happyville/home', 'happyville/shop', 'happyville/inn-front', 'overworld']);
});

it('takes the direction of a place from where its door sits', function () {
  writeHappyvilleMaps();

  $links = RegionMap::areas()['happyville/town-center']->links;

  // The house's door is up on the north-west side of the square because the
  // house is north-west of it.
  expect($links['happyville/home'])->toBe(CompassDirection::NORTH_WEST)
    ->and($links['happyville/shop'])->toBe(CompassDirection::SOUTH)
    ->and($links['happyville/inn-front'])->toBe(CompassDirection::SOUTH_EAST)
    ->and($links['overworld'])->toBe(CompassDirection::EAST);
});

it('groups a region and leaves other regions out of it', function () {
  writeHappyvilleMaps();

  expect(array_keys(RegionMap::inRegion('Happyville')))->toHaveCount(5)
    ->and(RegionMap::inRegion('Happyville'))->not->toHaveKey('overworld')
    // Region names come from map data, so they are matched the way an author
    // would expect rather than byte for byte.
    ->and(RegionMap::inRegion('happyville'))->toHaveCount(5);
});

it('places each part of the region where it actually is', function () {
  writeHappyvilleMaps();

  $placed = RegionMap::place('happyville/town-center');
  [$townX, $townY] = $placed['happyville/town-center'];

  expect($placed['happyville/home'])->toBe([$townX - 1, $townY - 1])
    ->and($placed['happyville/shop'])->toBe([$townX, $townY + 1])
    ->and($placed['happyville/inn-front'])->toBe([$townX + 1, $townY + 1])
    // The rooms are behind the inn, north of it.
    ->and($placed['happyville/inn-back'])->toBe([$townX + 1, $townY]);
});

it('keeps the same shape wherever in the region the player stands', function () {
  writeHappyvilleMaps();

  $fromTown = RegionMap::place('happyville/town-center');
  $fromHome = RegionMap::place('happyville/home');

  $shapeOf = static function (array $placed): array {
    $origin = $placed['happyville/town-center'];

    return array_map(
      static fn(array $position): array => [$position[0] - $origin[0], $position[1] - $origin[1]],
      $placed
    );
  };

  // The world does not rearrange itself around the player.
  expect($shapeOf($fromHome))->toBe($shapeOf($fromTown));
});

it('honours a position the project pins a place to', function () {
  writeTestMaps([
    'crypt/entrance' => ['name' => 'Entrance', 'region' => 'Crypt', 'to' => ['crypt/hall' => [10, 0]]],
    'crypt/hall' => ['name' => 'Hall', 'region' => 'Crypt', 'station' => ['x' => 4, 'y' => 4], 'to' => ['crypt/entrance' => [10, 9]]],
  ]);

  expect(RegionMap::place('crypt/entrance')['crypt/hall'])->toBe([4, 4]);
});

it('still places a room no door reaches from here', function () {
  writeTestMaps([
    'crypt/entrance' => ['name' => 'Entrance', 'region' => 'Crypt', 'to' => ['crypt/hall' => [10, 0]]],
    'crypt/hall' => ['name' => 'Hall', 'region' => 'Crypt', 'to' => ['crypt/entrance' => [10, 9]]],
    'crypt/vault' => ['name' => 'Sealed Vault', 'region' => 'Crypt'],
  ]);

  // A map only a cutscene can reach is still part of the region.
  expect(RegionMap::place('crypt/entrance'))->toHaveKey('crypt/vault');
});

it('never puts two places on the same spot', function () {
  writeTestMaps([
    'town/square' => [
      'name' => 'Square',
      'region' => 'Town',
      // Three doors along the same wall all point the same way.
      'to' => ['town/a' => [10, 9], 'town/b' => [11, 9], 'town/c' => [12, 9]],
    ],
    'town/a' => ['name' => 'A', 'region' => 'Town'],
    'town/b' => ['name' => 'B', 'region' => 'Town'],
    'town/c' => ['name' => 'C', 'region' => 'Town'],
  ]);

  $placed = RegionMap::place('town/square');
  $cells = array_map(static fn(array $position): string => implode(':', $position), $placed);

  expect(array_unique($cells))->toHaveCount(count($placed));
});

it('resolves duplicate pins without stealing later distinct authored pins', function () {
  writeTestMaps([
    'town/a' => ['name' => 'A', 'region' => 'Town', 'station' => ['x' => 0, 'y' => 0]],
    'town/b' => ['name' => 'B', 'region' => 'Town', 'station' => ['x' => 0, 'y' => 0]],
    'town/c' => ['name' => 'C', 'region' => 'Town', 'station' => ['x' => 1, 'y' => 0]],
  ]);

  $placed = RegionMap::place('town/a');
  expect($placed['town/a'])->toBe([0, 0])
    ->and($placed['town/c'])->toBe([1, 0])
    ->and($placed['town/b'])->toBe([2, 0])
    ->and(RegionMap::place('town/b'))->toBe($placed)
    ->and(RegionMap::areas()['town/b']->station)->toBe([0, 0]);
});

it('reserves disconnected pins and traverses pinned nodes before inferring their children', function () {
  writeTestMaps([
    'town/a' => ['name' => 'A', 'region' => 'Town', 'station' => ['x' => 0, 'y' => 0], 'to' => ['town/b' => [19, 5], 'town/c' => [10, 0]]],
    'town/b' => ['name' => 'B', 'region' => 'Town', 'station' => ['x' => 1, 'y' => 0], 'to' => ['town/d' => [19, 5]]],
    'town/c' => ['name' => 'C', 'region' => 'Town'],
    'town/d' => ['name' => 'D', 'region' => 'Town'],
    'town/e' => ['name' => 'E', 'region' => 'Town', 'station' => ['x' => 2, 'y' => 0]],
  ]);

  $placed = RegionMap::place('town/a');
  expect($placed['town/b'][0])->toBe($placed['town/a'][0] + 1)
    ->and($placed['town/e'][0])->toBe($placed['town/a'][0] + 2)
    ->and($placed['town/d'])->toBe([$placed['town/a'][0] + 3, $placed['town/a'][1]])
    ->and($placed['town/c'][1])->toBeLessThan($placed['town/a'][1]);
});

it('finds a free cell beyond thirty two occupied positions', function () {
  $maps = [];
  for ($index = 0; $index < 40; $index++) {
    $maps[sprintf('town/room-%02d', $index)] = [
      'name' => "Room $index", 'region' => 'Town', 'station' => ['x' => 0, 'y' => 0],
    ];
  }
  writeTestMaps($maps);

  $placed = RegionMap::place('town/room-00');
  expect(array_unique(array_map(static fn(array $cell): string => implode(':', $cell), $placed)))->toHaveCount(40)
    ->and($placed['town/room-39'])->toBe([39, 0]);
});

it('reports the regions a region leads out to, once each', function () {
  writeHappyvilleMaps();

  $exits = RegionMap::exits('Happyville');

  expect($exits)->toHaveCount(1)
    ->and($exits[0]->region)->toBe('Overworld')
    ->and($exits[0]->name)->toBe('Overworld');
});

it('has nothing to place for a map it has never heard of', function () {
  writeHappyvilleMaps();

  expect(RegionMap::place('atlantis/deep'))->toBe([]);
});

it('reads no maps at all when a project has none', function () {
  RegionMap::loadFrom(sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ichiloto-empty-', true));

  expect(RegionMap::areas())->toBe([]);
});
