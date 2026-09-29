<?php

use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Tilesets\AutotileShape;
use Ichiloto\Engine\Rendering\Tilesets\TileAnimation;
use Ichiloto\Engine\Rendering\Tilesets\TileComposer;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TilePiece;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use Ichiloto\Engine\Rendering\Tilesets\TilesetSheet;

use function Tests\Support\Rendering\writeTestPng;

require_once dirname(__DIR__) . '/Support/Rendering/GraphicalSpriteFixtures.php';

/** @param list<TilePiece> $pieces @return list<array{int, int, int, int, int, int}> */
function getPieceRects(array $pieces): array
{
  return array_map(static fn(TilePiece $piece): array => [$piece->x, $piece->y, $piece->width, $piece->height, $piece->left, $piece->top], $pieces);
}

function writeTilesetProject(string $root, array $sheets = ['A2' => [768, 576], 'B' => [768, 768]], string $extra = ''): void
{
  $entries = [];
  foreach ($sheets as $name => [$width, $height]) {
    writeTestPng("{$root}/Graphics/Tilesets/{$name}.png", $width, $height);
    $entries[] = "'{$name}' => 'Graphics/Tilesets/{$name}.png'";
  }
  @mkdir("{$root}/Data/Tilesets", 0777, true);
  file_put_contents("{$root}/Data/Tilesets/home.php", "<?php\nreturn ['name' => 'Home', 'sheets' => [" . implode(', ', $entries) . "]{$extra}];\n");
}

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-tileset-' . bin2hex(random_bytes(6));
  mkdir($this->root, 0777, true);
});

afterEach(function () {
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

it('numbers tiles as RPG Maker MZ does', function () {
  expect(TileId::getSheet(0))->toBe(TilesetSheet::B)
    ->and(TileId::getSheet(300))->toBe(TilesetSheet::C)
    ->and(TileId::getSheet(1536))->toBe(TilesetSheet::A5)
    ->and(TileId::getSheet(1700))->toBeNull()
    ->and(TileId::getSheet(2048))->toBe(TilesetSheet::A1)
    ->and(TileId::getSheet(2816))->toBe(TilesetSheet::A2)
    ->and(TileId::getSheet(4352))->toBe(TilesetSheet::A3)
    ->and(TileId::getSheet(5888))->toBe(TilesetSheet::A4)
    ->and(TileId::getSheet(8192))->toBeNull()
    ->and(TileId::getKind(2816))->toBe(16)
    ->and(TileId::getShape(2816 + 47))->toBe(47)
    ->and(TileId::getAutotileId(17, 3))->toBe(2816 + 48 + 3)
    ->and(TileId::getFlagId(2816 + 20))->toBe(2816)
    ->and(TileId::isWaterfall(TileId::getAutotileId(5, 0)))->toBeTrue()
    ->and(TileId::isWall(TileId::getAutotileId(88, 0)))->toBeTrue()
    ->and(TileId::isWall(TileId::getAutotileId(80, 0)))->toBeFalse();
});

it('copies plain tiles from the two eight-column halves of their sheet', function () {
  expect(getPieceRects(TileComposer::compose(1, 48)[0]))->toBe([[48, 0, 48, 48, 0, 0]])
    ->and(getPieceRects(TileComposer::compose(9, 48)[0]))->toBe([[48, 48, 48, 48, 0, 0]])
    ->and(getPieceRects(TileComposer::compose(130, 48)[0]))->toBe([[480, 0, 48, 48, 0, 0]])
    ->and(TileComposer::compose(1536 + 3, 48)[0][0]->sheet)->toBe(TilesetSheet::A5)
    ->and(fn() => TileComposer::compose(0, 48))->toThrow(InvalidArgumentException::class)
    ->and(fn() => TileComposer::compose(1, 47))->toThrow(InvalidArgumentException::class);
});

it('assembles autotiles from the quarters RPG Maker chooses for each shape', function () {
  // The first A2 kind, isolated (shape 47): its preview tile, the block's first two by two quarters.
  expect(getPieceRects(TileComposer::compose(2816 + 47, 48)[0]))->toBe([
    [0, 0, 24, 24, 0, 0], [24, 0, 24, 24, 24, 0], [0, 24, 24, 24, 0, 24], [24, 24, 24, 24, 24, 24],
  ])
    // The second A2 kind surrounded (shape 0): interior quarters of the block two tiles right.
    ->and(getPieceRects(TileComposer::compose(2816 + 48, 48)[0]))->toBe([
      [144, 96, 24, 24, 0, 0], [120, 96, 24, 24, 24, 0], [144, 72, 24, 24, 0, 24], [120, 72, 24, 24, 24, 24],
    ])
    // A4's first wall-side row starts three tiles down and uses the wall table.
    ->and(getPieceRects(TileComposer::compose(TileId::getAutotileId(88, 15), 48)[0]))->toBe([
      [0, 144, 24, 24, 0, 0], [72, 144, 24, 24, 24, 0], [0, 216, 24, 24, 0, 24], [72, 216, 24, 24, 24, 24],
    ]);
});

it('animates A1 water and waterfalls on RPG Maker frames', function () {
  $water = TileComposer::compose(TileId::getAutotileId(0, 47), 48);
  $waterfall = TileComposer::compose(TileId::getAutotileId(5, 0), 48);
  expect($water)->toHaveCount(4)
    ->and(array_map(static fn(array $frame): int => $frame[0]->x, $water))->toBe([0, 96, 192, 96])
    ->and($waterfall)->toHaveCount(3)
    ->and(array_map(static fn(array $frame): int => $frame[0]->y, $waterfall))->toBe([0, 48, 96])
    ->and(TileComposer::compose(TileId::getAutotileId(2, 47), 48))->toHaveCount(1)
    ->and(TileAnimation::getFrame(0.0))->toBe(0)
    ->and(TileAnimation::getFrame(0.5))->toBe(1)
    ->and(TileAnimation::getFrame(6.0))->toBe(0);
});

it('draws table legs beneath the top of an A2 table autotile', function () {
  $table = TileComposer::compose(2816 + 47, 48, table: true)[0];
  expect($table)->toHaveCount(6)
    ->and(getPieceRects(array_slice($table, 2, 2)))->toBe([[0, 72, 24, 24, 0, 24], [0, 24, 24, 12, 0, 36]]);
});

it('chooses autotile shapes from neighbours of the same kind, with the map edge counting as the same', function () {
  $floor = TileId::getAutotileId(16, 0);
  $e = 0;
  $shapes = static fn(array $layer): array => array_map(static fn(array $row): array => array_map(
    static fn(int $id): int => TileId::isAutotile($id) ? TileId::getShape($id) : -1, $row), AutotileShape::resolveLayer($layer));
  expect($shapes([
    [$e, $e, $e, $e, $e],
    [$e, $floor, $floor, $floor, $e],
    [$e, $floor, $floor, $floor, $e],
    [$e, $floor, $floor, $floor, $e],
    [$e, $e, $e, $e, $floor],
  ]))->toBe([
    [-1, -1, -1, -1, -1],
    [-1, 34, 20, 36, -1],
    [-1, 16, 0, 24, -1],
    [-1, 40, 28, 38, -1],
    [-1, -1, -1, -1, 34],
  ])
    ->and($shapes([[$e, $e, $e], [$floor, $floor, $floor], [$e, $e, $e]])[1])->toBe([33, 33, 33])
    ->and($shapes([[$e, $e, $e], [$e, $floor, $e], [$e, $e, $e]])[1][1])->toBe(46)
    ->and($shapes([[$e, TileId::getAutotileId(88, 0), $e]])[0][1])->toBe(5)
    ->and($shapes([[$e, TileId::getAutotileId(5, 0), $e]])[0][1])->toBe(3);
});

it('loads a tileset and uses only sheets whose images fit their RPG Maker layout', function () {
  writeTilesetProject($this->root, ['A2' => [768, 576], 'A5' => [384, 768], 'B' => [700, 768], 'C' => [1536, 1536]],
    ", 'above' => [2816 + 5, 10], 'tables' => [2816 + 48]");
  $tileset = Tileset::load($this->root, 'home');
  expect($tileset->isAbove(2816 + 40))->toBeTrue()
    ->and($tileset->isAbove(2816 + 48))->toBeFalse()
    ->and($tileset->isTable(2816 + 60))->toBeTrue()
    ->and($tileset->getUsableSheets($this->root))->toBe(['tileSize' => 48,
      'sheets' => ['A2' => 'Graphics/Tilesets/A2.png', 'A5' => 'Graphics/Tilesets/A5.png']])
    ->and(fn() => Tileset::fromArray('bad', ['name' => 'Bad', 'sheets' => ['F' => 'x.png']]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => Tileset::fromArray('bad', ['name' => 'Bad', 'sheets' => ['B' => 'x.png'], 'tables' => [5]]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => Tileset::fromArray('bad', ['name' => 'Bad', 'sheets' => ['B' => '../x.png']]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => Tileset::load($this->root, '../home'))->toThrow(InvalidArgumentException::class);
});

it('reads map tile layers as literal cells matching the map and refuses anything else', function () {
  $layers = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', "....\n....")]);
  $layer = new MapTileLayer('floor', 1, 'floor', "2816 0 10 10\n  0 0  10 0 \n");
  expect($layer->tiles)->toBe([[2816, 0, 10, 10], [0, 0, 10, 0]])
    ->and($layer->getEntries())->toBe([['2816', '0', '10', '10'], ['0', '0', '10', '0']])
    ->and($layer->getUsedIds())->toBe([2816, 10]);
  $layer->assertMatches($layers);
  expect(fn() => new MapTileLayer('floor', 1, 'floor', "1700 0"))->toThrow(InvalidArgumentException::class)
    // A field cell holds one whole tile: an entry naming half a tile is refused, never reinterpreted.
    ->and(fn() => new MapTileLayer('floor', 1, 'floor', "10L 10R"))->toThrow(InvalidArgumentException::class,
      "Tile layer floor row 0, cell 0: '10L' names half of tile 10, but each field cell holds one whole tile; use whole tiles, such as '10'.")
    ->and(fn() => new MapTileLayer('floor', 1, 'floor', "0 10R"))->toThrow(InvalidArgumentException::class, "cell 1: '10R' names half of tile 10")
    ->and(fn() => new MapTileLayer('floor', 1, 'floor', "2816L 0"))->toThrow(InvalidArgumentException::class, 'one whole tile')
    ->and(fn() => new MapTileLayer('floor', 1, 'floor', "0R 0"))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new MapTileLayer('floor', 1, 'floor', "10X 0"))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new MapTileLayer('floor', 1, 'floor', "x 0"))->toThrow(InvalidArgumentException::class)
    ->and(fn() => (new MapTileLayer('floor', 1, 'floor', "0 0 0\n0 0"))->assertMatches($layers))->toThrow(InvalidArgumentException::class);
});

it('uploads the tileset, tile layers in their draw bands and glyph-free rows with the world', function () {
  writeTilesetProject($this->root, extra: ", 'above' => [5]");
  $map = $this->root . '/Maps/home';
  mkdir($map . '/graphics', 0777, true);
  file_put_contents($map . '/graphics/01.floor.tiles.php', MapGridSource::buildSource("2816 2816 2816 2816\n2816 2816 0 0", 'TILES'));
  file_put_contents($map . '/graphics/05.furniture.tiles.php', MapGridSource::buildSource("0 0 5 5\n1 0 0 0", 'TILES'));
  $layers = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', "....\n....")]);
  $graphics = MapGraphics::loadFromDirectory($map, 'home', 'home', $layers, $this->root);
  $world = PresentationWorld::getFromLayers($layers, 'map', $graphics, $this->root);
  $value = $world->operations[0]['value'];
  expect(array_map(static fn(array $layer): array => [$layer['id'], $layer['layer'], $layer['kind']], $value['layers']))->toBe([
    ['map:terrain', -99, 'gameplay'],
    ['tiles:floor', -99, 'tiles'],
    ['tiles:furniture', -95, 'tiles'],
    ['tiles:furniture:above', 905, 'tiles'],
  ])
    ->and($value['tileset']['tileSize'])->toBe(48)
    ->and($value['tileset']['sheets'])->toBe(['Graphics/Tilesets/A2.png', 'Graphics/Tilesets/B.png'])
    ->and($world->textLayerIds)->toBe(['map:terrain'])
    ->and($world->animated)->toBeFalse();
  $tiles = array_values(array_filter($world->operations, static fn(array $operation): bool => $operation['op'] === 'worldTiles'));
  expect(array_map(static fn(array $operation): array => [$operation['layerId'], $operation['rows'][0]['row'],
    array_column($operation['rows'][0]['cells'], 'column')], $tiles))->toBe([
    ['tiles:floor', 0, [0, 1, 2, 3]],
    ['tiles:floor', 1, [0, 1]],
    ['tiles:furniture', 1, [0]],
    ['tiles:furniture:above', 0, [2, 3]],
  ]);
  $catalog = $value['tileset']['tiles'];
  $at = static fn(int $index, int $cell): array => $catalog[$tiles[$index]['rows'][0]['cells'][$cell]['tile']];
  // Every cell shows one whole tile in its own cell: each floor cell its autotile composed for that
  // cell from all four quarters, and each plain tile whole, with no narrowing or offset.
  foreach ([[0, 0], [0, 1], [0, 2], [0, 3], [1, 0], [1, 1]] as [$index, $cell]) {
    expect($at($index, $cell))->toHaveKeys(['frames'])->not->toHaveKeys(['width', 'left', 'top'])
      ->and(array_map(static fn(array $piece): array => [$piece['left'], $piece['top']], $at($index, $cell)['frames'][0]))
      ->toBe([[0, 0], [24, 0], [0, 24], [24, 24]]);
  }
  expect($tiles[3]['rows'][0]['cells'][0]['tile'])->toBe($tiles[3]['rows'][0]['cells'][1]['tile'])
    ->and($at(3, 0))->toBe(['frames' => [[['sheet' => 1, 'x' => 240, 'y' => 0, 'width' => 48, 'height' => 48, 'left' => 0, 'top' => 0]]]])
    ->and($at(2, 0))->toBe(['frames' => [[['sheet' => 1, 'x' => 48, 'y' => 0, 'width' => 48, 'height' => 48, 'left' => 0, 'top' => 0]]]]);
});

it('shifts a whole tile layer by half a field cell from the map data, never its cells', function () {
  writeTilesetProject($this->root);
  $map = $this->root . '/Maps/home';
  mkdir($map . '/graphics', 0777, true);
  file_put_contents($map . '/graphics/01.floor.tiles.php', MapGridSource::buildSource("2816 2816\n2816 2816", 'TILES'));
  file_put_contents($map . '/graphics/02.lounge.tiles.php', MapGridSource::buildSource("0 0\n5 0", 'TILES'));
  $layers = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', "..\n..")]);
  $graphics = MapGraphics::loadFromDirectory($map, 'home', 'home', $layers, $this->root, ['lounge' => ['offset' => [0.5, -0.5]]]);
  $world = PresentationWorld::getFromLayers($layers, 'map', $graphics, $this->root);
  $tiles = array_values(array_filter($world->operations, static fn(array $operation): bool => $operation['op'] === 'worldTiles'));
  $lounge = $tiles[array_key_last($tiles)];
  $tile = $world->operations[0]['value']['tileset']['tiles'][$lounge['rows'][0]['cells'][0]['tile']];
  // A cell is one tile, so half a cell is 24 pixels on either axis.
  expect($graphics->offsets)->toBe(['lounge' => [0.5, -0.5]])
    ->and([$lounge['layerId'], $lounge['rows'][0]['row'], $lounge['rows'][0]['cells'][0]['column']])->toBe(['tiles:lounge', 1, 0])
    ->and([$tile['left'], $tile['top']])->toBe([24, -24])
    ->and(MapGraphics::readLayerOffsets(['lounge' => ['offset' => [0, 0]]], ['lounge'], 'home'))->toBe(['lounge' => [0.0, 0.0]])
    ->and(MapGraphics::readLayerOffsets(null, [], 'home'))->toBe([]);
  foreach ([['sofa' => ['offset' => [0, 0.5]]], ['lounge' => ['offset' => [0, 1]]], ['lounge' => ['offset' => [0.25, 0]]],
    ['lounge' => ['offset' => [0]]], ['lounge' => ['offset' => [0, 0], 'above' => true]], ['lounge' => []], 'lounge'] as $settings) {
    expect(fn() => MapGraphics::readLayerOffsets($settings, ['floor', 'lounge'], 'home'))->toThrow(InvalidArgumentException::class);
  }
});

it('reads the gameplay layer each tile layer moves with, and loads a map that names one', function () {
  $settings = ['floor' => ['movesWith' => 'buildings'], 'lounge' => ['offset' => [0, -0.5], 'movesWith' => 'fixtures']];
  expect(MapGraphics::readLayersMovingWith($settings, ['floor', 'lounge'], ['buildings', 'fixtures'], 'home'))
    ->toBe(['floor' => 'buildings', 'lounge' => 'fixtures'])
    ->and(MapGraphics::readLayerOffsets($settings, ['floor', 'lounge'], 'home'))->toBe(['lounge' => [0.0, -0.5]])
    ->and(MapGraphics::readLayersMovingWith(null, [], ['terrain'], 'home'))->toBe([]);
  foreach ([['floor' => ['movesWith' => 'roof']], ['floor' => ['movesWith' => ['buildings']]]] as $invalid) {
    expect(fn() => MapGraphics::readLayersMovingWith($invalid, ['floor'], ['buildings'], 'home'))->toThrow(InvalidArgumentException::class);
  }
  writeTilesetProject($this->root);
  $map = $this->root . '/Maps/home';
  mkdir($map . '/graphics', 0777, true);
  file_put_contents($map . '/graphics/01.floor.tiles.php', MapGridSource::buildSource('2816 2816', 'TILES'));
  $layers = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', '..'), new MapLayer('detail', 2, true, 'detail', '..')]);
  expect(MapGraphics::loadFromDirectory($map, 'home', 'home', $layers, $this->root, ['floor' => ['movesWith' => 'terrain']]))->not->toBeNull()
    // A decoration layer is not something tiles can move with.
    ->and(fn() => MapGraphics::loadFromDirectory($map, 'home', 'home', $layers, $this->root, ['floor' => ['movesWith' => 'detail']]))
    ->toThrow(InvalidArgumentException::class);
});

it('reads whole pieces from the tileset with glyphs and tiles over one footprint', function () {
  $tileset = Tileset::fromArray('home', ['name' => 'Home', 'sheets' => ['B' => 'Graphics/Tilesets/B.png'], 'pieces' => [
    'bed' => ['name' => 'Bed', 'layer' => 'fixtures', 'glyphs' => ['=', '='], 'tiles' => ['furniture' => ['32', '40']]],
    'table' => ['name' => 'Table', 'layer' => 'fixtures', 'glyphs' => ['##'], 'tiles' => ['furniture' => ['42 43']]],
    'rug' => ['name' => 'Rug corner', 'layer' => 'fixtures', 'glyphs' => [' r']],
  ]]);
  $bed = $tileset->pieces['bed'];
  expect(array_keys($tileset->pieces))->toBe(['bed', 'table', 'rug'])
    ->and([$bed->name, $bed->layer, $bed->width, $bed->height])->toBe(['Bed', 'fixtures', 1, 2])
    ->and($bed->glyphs)->toBe([['='], ['=']])
    ->and($bed->tiles)->toBe(['furniture' => [['32'], ['40']]])
    ->and($tileset->pieces['table']->tiles['furniture'])->toBe([['42', '43']])
    ->and($tileset->pieces['rug']->glyphs)->toBe([[' ', 'r']])
    ->and($tileset->pieces['rug']->tiles)->toBe([])
    ->and(Tileset::fromArray('plain', ['name' => 'Plain', 'sheets' => ['B' => 'b.png']])->pieces)->toBe([]);
  $piece = static fn(array $bed): array => ['name' => 'Home', 'sheets' => ['B' => 'b.png'], 'pieces' => ['bed' => $bed]];
  $bad = ['name' => 'Bed', 'layer' => 'fixtures', 'glyphs' => ['=', '=']];
  foreach ([
    ['layer' => 'no spaces'] + $bad,
    ['glyphs' => []] + $bad,
    ['glyphs' => ['==', '=']] + $bad,
    ['glyphs' => ["\u{754c}"]] + $bad,
    ['tiles' => ['furniture' => ['32']]] + $bad,
    ['tiles' => ['furniture' => ['32', '40 0']]] + $bad,
    ['colour' => 'red'] + $bad,
  ] as $case) {
    expect(fn() => Tileset::fromArray('home', $piece($case)))->toThrow(InvalidArgumentException::class);
  }
  // A piece holds one whole tile per cell, as a tile layer does.
  expect(fn() => Tileset::fromArray('home', $piece(['tiles' => ['furniture' => ['42L', '42R']]] + $bad)))
    ->toThrow(InvalidArgumentException::class, "piece bed tiles furniture row 0, cell 0: '42L' names half of tile 42, but each field cell holds one whole tile");
  expect(fn() => Tileset::fromArray('home', ['name' => 'Home', 'sheets' => ['B' => 'b.png'], 'pieces' => [$bad]]))
    ->toThrow(InvalidArgumentException::class, 'keyed by piece id')
    ->and(fn() => Tileset::fromArray('home', ['name' => 'Home', 'sheets' => ['B' => 'b.png'], 'pieces' => ['Bed' => $bad]]))
    ->toThrow(InvalidArgumentException::class, 'piece id');
});

it('reads connected wall pieces and shapes each cell from its neighbours', function () {
  $wall = ['name' => 'Wall', 'layer' => 'buildings', 'connects' => 'lines',
    'glyphs' => ['horizontal' => '-', 'vertical' => '|', 'corner' => '+'], 'tiles' => ['walls' => '5888']];
  $fence = ['name' => 'Fence', 'layer' => 'fixtures', 'connects' => 'lines',
    'glyphs' => ['horizontal' => '=', 'vertical' => '!', 'corner' => '#'],
    'tiles' => ['fences' => ['horizontal' => '12', 'vertical' => '13', 'corner' => '14']]];
  $pieces = Tileset::fromArray('home', ['name' => 'Home', 'sheets' => ['B' => 'b.png'],
    'pieces' => ['wall' => $wall, 'fence' => $fence]])->pieces;
  $piece = $pieces['wall'];
  expect([$piece->connects, $piece->width, $piece->height])->toBe([TilesetPiece::LINES, 1, 1])
    ->and($piece->shapes)->toBe(['horizontal' => '-', 'vertical' => '|', 'corner' => '+'])
    ->and($piece->shapeTiles)->toBe(['walls' => ['horizontal' => '5888', 'vertical' => '5888', 'corner' => '5888']])
    ->and($pieces['fence']->shapeTiles['fences'])->toBe(['horizontal' => '12', 'vertical' => '13', 'corner' => '14'])
    ->and([$piece->isMember('-'), $piece->isMember("\e[33m|\e[0m"), $piece->isMember('+'), $piece->isMember('_'), $piece->isMember(' ')])
    ->toBe([true, true, true, false, false])
    // Joined only across, only down, or both ways; a lone post is a corner.
    ->and($piece->getLineShape(false, true, false, true))->toBe('horizontal')
    ->and($piece->getLineShape(false, false, false, true))->toBe('horizontal')
    ->and($piece->getLineShape(true, false, true, false))->toBe('vertical')
    ->and($piece->getLineShape(true, true, false, false))->toBe('corner')
    ->and($piece->getLineShape(true, true, true, false))->toBe('corner')
    ->and($piece->getLineShape(false, false, false, false))->toBe('corner');
  $tileset = static fn(array $piece): array => ['name' => 'Home', 'sheets' => ['B' => 'b.png'], 'pieces' => ['wall' => $piece]];
  foreach ([
    ['connects' => 'areas'] + $wall,
    ['glyphs' => ['horizontal' => '-', 'vertical' => '|']] + $wall,
    ['glyphs' => ['horizontal' => '-', 'vertical' => '-', 'corner' => '+']] + $wall,
    ['glyphs' => ['horizontal' => '--', 'vertical' => '|', 'corner' => '+']] + $wall,
    ['glyphs' => ['horizontal' => ' ', 'vertical' => '|', 'corner' => '+']] + $wall,
    ['tiles' => ['walls' => ['horizontal' => '5888']]] + $wall,
    ['tiles' => ['walls' => '5888 5888']] + $wall,
    ['tiles' => ['walls' => '99999']] + $wall,
    ['tiles' => ['walls' => '5888L']] + $wall,
  ] as $case) {
    expect(fn() => Tileset::fromArray('home', $tileset($case)))->toThrow(InvalidArgumentException::class);
  }
});

it('leaves a map on its glyphs when its graphics or every sheet are unusable', function () {
  writeTilesetProject($this->root, ['A2' => [100, 100]]);
  $map = $this->root . '/Maps/home';
  mkdir($map . '/graphics', 0777, true);
  file_put_contents($map . '/graphics/01.floor.tiles.php', MapGridSource::buildSource("2816 0", 'TILES'));
  $layers = new MapLayerSet([new MapLayer('terrain', 1, false, 'terrain', "..")]);
  $graphics = MapGraphics::loadFromDirectory($map, 'home', 'home', $layers, $this->root);
  $world = PresentationWorld::getFromLayers($layers, 'map', $graphics, $this->root);
  expect($world->operations[0]['value'])->not->toHaveKey('tileset')
    ->and(MapGraphics::loadFromDirectory($this->root . '/Maps/none', 'none', null, $layers, $this->root))->toBeNull()
    ->and(fn() => MapGraphics::loadFromDirectory($map, 'home', null, $layers, $this->root))->toThrow(InvalidArgumentException::class)
    ->and(fn() => MapGraphics::loadFromDirectory($map, 'home', 'missing', $layers, $this->root))->toThrow(InvalidArgumentException::class);
});

it('sends the tile animation frame only when it moves', function () {
  $viewport = static fn(int $frame): PresentationViewport => new PresentationViewport(1.0, 0, 0, new CanvasRectangle(0, 0, 10, 10),
    worldId: 'map', tileFrame: $frame);
  expect($viewport(0)->toArray())->not->toHaveKey('tileFrame')
    ->and($viewport(3)->toArray()['tileFrame'])->toBe(3)
    ->and(fn() => $viewport(-1))->toThrow(InvalidArgumentException::class);
});
