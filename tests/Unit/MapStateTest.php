<?php

use Ichiloto\Engine\Field\RegionMap;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\PartyLocation;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\MapState;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

/**
 * Draws the region map without a running game: where the party has been and
 * where they are standing are the only things the drawing needs from one.
 */
class RegionMapDrawingProbe extends MapState
{
  private GameScene $scene;
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

  public function moveView(int $horizontal, int $vertical): bool
  {
    return $this->pan($horizontal, $vertical);
  }

  public function showPanels(): array
  {
    $this->scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
    $party = new Party();
    $area = RegionMap::areas()[$this->currentId];
    $party->location = new PartyLocation($area->name, $area->region);
    new ReflectionProperty(GameScene::class, 'party')->setValue($this->scene, $party);
    $this->calculateMargins();
    $this->initializeUI();
    $this->refreshUI();
    return [$this->mapPanel, $this->infoPanel];
  }

  public function getGameScene(): GameScene
  {
    return $this->scene;
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

function writeSparseRegionMaps(): array
{
  $maps = [
    'roads/garden' => ['name' => 'Garden', 'region' => 'Roads', 'station' => ['x' => 8, 'y' => 3], 'to' => ['roads/waymeet' => [19, 5], 'roads/lantern' => [0, 9]]],
    'roads/waymeet' => ['name' => 'Waymeet', 'region' => 'Roads', 'station' => ['x' => 32, 'y' => 13]],
    'roads/lantern' => ['name' => 'Lanternrest', 'region' => 'Roads', 'station' => ['x' => 2, 'y' => 21], 'to' => ['roads/temple' => [10, 9]]],
    'roads/temple' => ['name' => 'Temple', 'region' => 'Roads', 'station' => ['x' => 2, 'y' => 31]],
  ];
  writeTestMaps($maps);
  return $maps;
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

it('opens sparse regions on the current location without changing their geometry', function (int $width, int $height) {
  $maps = writeSparseRegionMaps();
  $shape = RegionMap::place('roads/garden');
  foreach ($maps as $id => $map) {
    $rows = new RegionMapDrawingProbe($id, [$id])->draw($width, $height);
    expect(findOnMap($rows, $map['name']))->not->toBeNull()
      ->and($rows)->toHaveCount($height)
      ->and(RegionMap::place($id))->toBe($shape);
  }
})->with([[106, 26], [76, 18], [36, 8]]);

it('lets arrow-sized steps reach every discovered place in a sparse region', function (int $width, int $height) {
  $maps = writeSparseRegionMaps();
  $probe = new RegionMapDrawingProbe('roads/garden', array_keys($maps));
  $probe->draw($width, $height);
  while ($probe->moveView(-1, -1)) {}
  $found = [];
  $frames = 0;
  do {
    do {
      $rows = $probe->draw($width, $height);
      foreach ($maps as $id => $map) {
        if (findOnMap($rows, $map['name']) !== null) { $found[$id] = true; }
      }
      if (++$frames > 2000) { throw new RuntimeException('Map panning did not reach its bounds.'); }
    } while ($probe->moveView(1, 0));
    while ($probe->moveView(-1, 0)) {}
  } while ($probe->moveView(0, 1));
  expect($found)->toHaveCount(count($maps));
})->with([[106, 26], [76, 18], [36, 8]]);

it('clips very distant links to the viewport instead of allocating the world grid', function () {
  writeTestMaps([
    'world/a' => ['name' => 'Alpha', 'region' => 'World', 'station' => ['x' => 0, 'y' => 0], 'to' => ['world/b' => [19, 9]]],
    'world/b' => ['name' => 'Beta', 'region' => 'World', 'station' => ['x' => 1000000, 'y' => 1000000]],
  ]);
  $probe = new RegionMapDrawingProbe('world/b', ['world/a', 'world/b']);
  $rows = $probe->draw(76, 18);
  expect(findOnMap($rows, 'Beta'))->not->toBeNull()->and($rows)->toHaveCount(18);
  foreach ($rows as $row) {
    expect(TerminalText::displayWidth($row))->toBeLessThanOrEqual(76);
  }
});

it('keeps real map windows inside the screen and handles pan and Home through input', function (int $width, int $height) {
  writeSparseRegionMaps();
  $oldSettings = ConfigStore::has(PlaySettings::class) ? ConfigStore::get(PlaySettings::class) : null;
  $snapshots = [];
  foreach ([Console::class, InputManager::class] as $class) {
    $snapshots[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => $width, 'height' => $height]));
  foreach (['width' => $width, 'height' => $height, 'buffer' => [], 'frameDepth' => 0, 'frameRows' => [], 'terminalHandedBack' => false] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  ob_start();
  try {
    $probe = new RegionMapDrawingProbe('roads/garden', array_keys(RegionMap::areas()));
    [$map, $info] = $probe->showPanels();
    $initial = $map->getContent();
    $buffer = Console::getBuffer();
    expect(findOnMap($initial, 'Garden'))->not->toBeNull()
      ->and(findOnMap($buffer, 'Garden'))->not->toBeNull()
      ->and($map->getContent())->toHaveCount($map->getContentHeight())
      ->and($map->getHeightPolicy())->toBe(WindowHeightPolicy::FIXED)
      ->and($info->getHeightPolicy())->toBe(WindowHeightPolicy::FIXED);
    foreach ([$map, $info] as $panel) {
      expect($panel->getPosition()->x + $panel->getWidth())->toBeLessThanOrEqual($width)
        ->and($panel->getPosition()->y + $panel->getHeight())->toBeLessThanOrEqual($height);
    }
    foreach ($initial as $row) {
      expect(TerminalText::displayWidth($row))->toBeLessThanOrEqual($map->getContentWidth());
    }
    InputManager::setBindings(['right' => ['keys' => [KeyCode::RIGHT]]]);
    InputManager::setInputSource(new FakeInputSource(KeyCode::RIGHT, KeyCode::HOME));
    InputManager::handleInput();
    $probe->execute();
    expect($map->getContent())->not->toBe($initial);
    InputManager::handleInput();
    $probe->execute();
    expect($map->getContent())->toBe($initial);
  } finally {
    ob_end_clean();
    foreach ($snapshots as $class => $properties) {
      foreach ($properties as $name => $value) {
        new ReflectionProperty($class, $name)->setValue(null, $value);
      }
    }
    $oldSettings === null ? ConfigStore::remove(PlaySettings::class) : ConfigStore::put(PlaySettings::class, $oldSettings);
  }
})->with([[135, 36], [80, 24]]);
