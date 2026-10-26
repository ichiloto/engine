<?php

use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Tilesets\AutotileShape;
use Ichiloto\Engine\Rendering\Tilesets\TileComposer;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TilePiece;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;

final class AutotileTopologyFixture
{
  public const int TILE_SIZE = 8;
  public const array DIRECTIONS = [
    'NW' => [-1, -1], 'N' => [0, -1], 'NE' => [1, -1], 'E' => [1, 0],
    'SE' => [1, 1], 'S' => [0, 1], 'SW' => [-1, 1], 'W' => [-1, 0],
  ];

  public static function createNeighborhood(array $missing): array
  {
    $layer = array_fill(0, 3, array_fill(0, 3, TileId::getAutotileId(16, 47)));
    foreach ($missing as $direction) {
      [$dx, $dy] = self::DIRECTIONS[$direction];
      $layer[1 + $dy][1 + $dx] = TileId::EMPTY;
    }
    return $layer;
  }

  /** An independent corner oracle: each quarter depends on its two edges and its own diagonal. */
  public static function getQuarterSources(array $layer, int $x, int $y): array
  {
    $kind = TileId::getKind($layer[$y][$x]);
    $same = static function (int $dx, int $dy) use ($layer, $x, $y, $kind): bool {
      $id = $layer[$y + $dy][$x + $dx] ?? null;
      return $id === null || (TileId::isAutotile($id) && TileId::getKind($id) === $kind);
    };
    // TL, TR, BL, BR: interior, concave, horizontal edge, vertical edge, convex.
    $quarters = [
      [-1, -1, [2, 4], [2, 0], [2, 2], [0, 4], [0, 2]],
      [1, -1, [1, 4], [3, 0], [1, 2], [3, 4], [3, 2]],
      [-1, 1, [2, 3], [2, 1], [2, 5], [0, 3], [0, 5]],
      [1, 1, [1, 3], [3, 1], [1, 5], [3, 3], [3, 5]],
    ];
    return array_map(static function (array $quarter) use ($same): array {
      [$dx, $dy, $interior, $concave, $horizontal, $vertical, $convex] = $quarter;
      $alongX = $same($dx, 0);
      $alongY = $same(0, $dy);
      return match (true) {
        $alongX && $alongY => $same($dx, $dy) ? $interior : $concave,
        $alongX => $horizontal,
        $alongY => $vertical,
        default => $convex,
      };
    }, $quarters);
  }

  /** Distinct quarter colors, with transparent and translucent pixels; kind 17 is opaque backing. */
  public static function createSheet(): array
  {
    $pixels = [];
    $half = intdiv(self::TILE_SIZE, 2);
    for ($y = 0; $y < 12 * self::TILE_SIZE; $y++) {
      $row = [];
      for ($x = 0; $x < 16 * self::TILE_SIZE; $x++) {
        $qx = intdiv($x, $half);
        $qy = intdiv($y, $half);
        $alpha = $y % $half === 0 ? match ($x % $half) { 0 => 0, 1 => 128, default => 255 } : 255;
        if ($qx >= 4 && $qx < 8 && $qy < 6) { $alpha = 255; }
        $row[] = [1 + $qx * 7, 1 + $qy * 10, ($qx + $qy * 32) % 255, $alpha];
      }
      $pixels[] = $row;
    }
    return $pixels;
  }

  public static function writeSheet(string $root, array $pixels): void
  {
    $bytes = '';
    foreach ($pixels as $row) {
      $bytes .= "\0";
      foreach ($row as $pixel) { $bytes .= pack('C4', ...$pixel); }
    }
    $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data
      . pack('N', crc32($type . $data));
    mkdir($root . '/Graphics', 0700, true);
    file_put_contents($root . '/Graphics/SyntheticA2.png', "\x89PNG\r\n\x1a\n"
      . $chunk('IHDR', pack('NNCCCCC', count($pixels[0]), count($pixels), 8, 6, 0, 0, 0))
      . $chunk('IDAT', gzcompress($bytes)) . $chunk('IEND', ''));
  }

  /** @param list<TilePiece|array<string, int>> $pieces */
  public static function copyPixels(array $pieces, array $sheet): array
  {
    $pixels = array_fill(0, self::TILE_SIZE, array_fill(0, self::TILE_SIZE, [0, 0, 0, 0]));
    foreach ($pieces as $piece) {
      $piece = $piece instanceof TilePiece ? get_object_vars($piece) : $piece;
      for ($y = 0; $y < $piece['height']; $y++) {
        for ($x = 0; $x < $piece['width']; $x++) {
          $pixels[$piece['top'] + $y][$piece['left'] + $x] = $sheet[$piece['y'] + $y][$piece['x'] + $x];
        }
      }
    }
    return $pixels;
  }

  public static function getExpectedPixels(array $quarters, array $sheet): array
  {
    $half = intdiv(self::TILE_SIZE, 2);
    $pixels = [];
    for ($y = 0; $y < self::TILE_SIZE; $y++) {
      $row = [];
      for ($x = 0; $x < self::TILE_SIZE; $x++) {
        [$qx, $qy] = $quarters[intdiv($y, $half) * 2 + intdiv($x, $half)];
        $row[] = $sheet[$qy * $half + $y % $half][$qx * $half + $x % $half];
      }
      $pixels[] = $row;
    }
    return $pixels;
  }

  public static function createWorld(string $root, array $layers): PresentationWorld
  {
    $height = count($layers[0]->tiles);
    $width = count($layers[0]->tiles[0]);
    $gameplay = new MapLayerSet([new MapLayer('terrain', 1, false, 'synthetic',
      implode("\n", array_fill(0, $height, str_repeat('.', $width))))]);
    $tileset = Tileset::fromArray('synthetic', ['name' => 'Synthetic', 'sheets' => ['A2' => 'Graphics/SyntheticA2.png']]);
    return PresentationWorld::getFromLayers($gameplay, 'synthetic', new MapGraphics($tileset, $layers), $root);
  }

  public static function createLayer(string $name, int $order, array $cells): MapTileLayer
  {
    return new MapTileLayer($name, $order, 'synthetic/' . $name . '.tiles.php',
      implode("\n", array_map(static fn(array $row): string => implode(' ', $row), $cells)));
  }

  public static function getCellPieces(PresentationWorld $world, string $layerId, int $x, int $y): array
  {
    foreach ($world->operations as $operation) {
      if ($operation['op'] !== 'worldTiles' || $operation['layerId'] !== $layerId) { continue; }
      foreach ($operation['rows'] as $row) {
        if ($row['row'] !== $y) { continue; }
        foreach ($row['cells'] as $cell) {
          if ($cell['column'] === $x) { return $world->operations[0]['value']['tileset']['tiles'][$cell['tile']]['frames'][0]; }
        }
      }
    }
    throw new RuntimeException("Missing synthetic cell {$layerId} ({$x}, {$y}).");
  }

  public static function rotateClockwise(array $layer): array
  {
    $size = count($layer);
    $rotated = $layer;
    for ($y = 0; $y < $size; $y++) {
      for ($x = 0; $x < $size; $x++) { $rotated[$x][$size - 1 - $y] = $layer[$y][$x]; }
    }
    return $rotated;
  }
}

$autotileCorners = [
  'concave TL (NW bit 1)' => [['NW'], 1, [[2, 0], [1, 4], [2, 3], [1, 3]]],
  'concave TR (NE bit 2)' => [['NE'], 2, [[2, 4], [3, 0], [2, 3], [1, 3]]],
  'concave BL (SW bit 8)' => [['SW'], 8, [[2, 4], [1, 4], [2, 1], [1, 3]]],
  'concave BR (SE bit 4)' => [['SE'], 4, [[2, 4], [1, 4], [2, 3], [3, 1]]],
  'convex TL' => [['N', 'W'], 34, [[0, 2], [1, 2], [0, 3], [1, 3]]],
  'convex TR' => [['N', 'E'], 36, [[2, 2], [3, 2], [2, 3], [3, 3]]],
  'convex BL' => [['S', 'W'], 40, [[0, 4], [1, 4], [0, 5], [1, 5]]],
  'convex BR' => [['S', 'E'], 38, [[2, 4], [3, 4], [2, 5], [3, 5]]],
  'convex TL with concave BR' => [['N', 'W', 'SE'], 35, [[0, 2], [1, 2], [0, 3], [3, 1]]],
  'convex TR with concave BL' => [['N', 'E', 'SW'], 37, [[2, 2], [3, 2], [2, 1], [3, 3]]],
  'convex BL with concave TR' => [['S', 'W', 'NE'], 41, [[0, 4], [3, 0], [0, 5], [1, 5]]],
  'convex BR with concave TL' => [['S', 'E', 'NW'], 39, [[2, 0], [3, 4], [2, 5], [3, 5]]],
];

it('maps every named corner to its TL TR BL BR source and preserves synthetic color and alpha',
  function (array $missing, int $shape, array $quarters) {
    $layer = AutotileTopologyFixture::createNeighborhood($missing);
    $resolved = AutotileShape::resolveLayer($layer);
    $pieces = TileComposer::compose($resolved[1][1], AutotileTopologyFixture::TILE_SIZE)[0];
    $sheet = AutotileTopologyFixture::createSheet();
    expect(TileId::getShape($resolved[1][1]))->toBe($shape)
      ->and(AutotileTopologyFixture::getQuarterSources($layer, 1, 1))->toBe($quarters)
      ->and(array_map(static fn(TilePiece $piece): array => [$piece->left, $piece->top], $pieces))
      ->toBe([[0, 0], [4, 0], [0, 4], [4, 4]])
      ->and(AutotileTopologyFixture::copyPixels($pieces, $sheet))
      ->toBe(AutotileTopologyFixture::getExpectedPixels($quarters, $sheet));
  })->with($autotileCorners);

it('composes each quarter from its own adjacency for all 256 eight-neighbor combinations', function (int $mask) {
  $missing = [];
  foreach (array_keys(AutotileTopologyFixture::DIRECTIONS) as $index => $direction) {
    if (($mask & (1 << $index)) !== 0) { $missing[] = $direction; }
  }
  $layer = AutotileTopologyFixture::createNeighborhood($missing);
  // Shape is presentation, not kind: differently shaped neighbors must still connect.
  foreach ($layer as $y => $row) {
    foreach ($row as $x => $id) {
      if ($id !== TileId::EMPTY) { $layer[$y][$x] = TileId::getAutotileId(16, ($y * 3 + $x) * 5); }
    }
  }
  $before = $layer;
  $resolved = AutotileShape::resolveLayer($layer);
  $sheet = AutotileTopologyFixture::createSheet();
  $pieces = TileComposer::compose($resolved[1][1], AutotileTopologyFixture::TILE_SIZE)[0];
  expect(AutotileTopologyFixture::copyPixels($pieces, $sheet))
    ->toBe(AutotileTopologyFixture::getExpectedPixels(AutotileTopologyFixture::getQuarterSources($layer, 1, 1), $sheet))
    ->and(TileId::getKind($resolved[1][1]))->toBe(16)
    ->and($layer)->toBe($before);
})->with(array_map(static fn(int $mask): array => [$mask], range(0, 255)));

it('keeps convex and concave stair-step neighbors distinct through all four rotations',
  function (int $turns, int $convexShape, int $concaveShape) {
    $tile = TileId::getAutotileId(16, 0);
    $layer = array_fill(0, 7, array_fill(0, 7, TileId::EMPTY));
    for ($y = 1; $y <= 4; $y++) {
      for ($x = 1; $x <= 5 - $y; $x++) { $layer[$y][$x] = $tile; }
    }
    [$convexX, $convexY, $concaveX, $concaveY] = [3, 2, 2, 2];
    for ($turn = 0; $turn < $turns; $turn++) {
      $layer = AutotileTopologyFixture::rotateClockwise($layer);
      [$convexX, $convexY] = [6 - $convexY, $convexX];
      [$concaveX, $concaveY] = [6 - $concaveY, $concaveX];
    }
    $resolved = AutotileShape::resolveLayer($layer);
    $sheet = AutotileTopologyFixture::createSheet();
    expect(TileId::getShape($resolved[$convexY][$convexX]))->toBe($convexShape)
      ->and(TileId::getShape($resolved[$concaveY][$concaveX]))->toBe($concaveShape);
    foreach ($layer as $y => $row) {
      foreach ($row as $x => $id) {
        if ($id === TileId::EMPTY) { expect($resolved[$y][$x])->toBe(TileId::EMPTY); continue; }
        expect(AutotileTopologyFixture::copyPixels(TileComposer::compose($resolved[$y][$x], 8)[0], $sheet))
          ->toBe(AutotileTopologyFixture::getExpectedPixels(AutotileTopologyFixture::getQuarterSources($layer, $x, $y), $sheet));
      }
    }
  })->with(['BR steps' => [0, 38, 4], 'BL steps' => [1, 40, 8], 'TL steps' => [2, 34, 1], 'TR steps' => [3, 36, 2]]);

it('uploads independent corner layers with alpha intact and only explicitly authored backing',
  function (array $missing, int $shape, array $quarters) {
    $root = createTestDirectory('ichiloto-autotile-topology-');
    $sheet = AutotileTopologyFixture::createSheet();
    AutotileTopologyFixture::writeSheet($root, $sheet);
    $upper = AutotileTopologyFixture::createLayer('ground', 2, AutotileTopologyFixture::createNeighborhood($missing));
    $lower = AutotileTopologyFixture::createLayer('backing', 1,
      array_fill(0, 3, array_fill(0, 3, TileId::getAutotileId(17, 0))));
    $world = AutotileTopologyFixture::createWorld($root, [$lower, $upper]);
    $upperOnly = AutotileTopologyFixture::createWorld($root, [$upper]);
    $upperPieces = AutotileTopologyFixture::getCellPieces($world, 'tiles:ground', 1, 1);
    $lowerPieces = AutotileTopologyFixture::getCellPieces($world, 'tiles:backing', 1, 1);
    $upperPixels = AutotileTopologyFixture::copyPixels($upperPieces, $sheet);
    $lowerPixels = AutotileTopologyFixture::copyPixels($lowerPieces, $sheet);
    expect($upperPieces)->toBe(AutotileTopologyFixture::getCellPieces($upperOnly, 'tiles:ground', 1, 1))
      ->and($upperPixels)->toBe(AutotileTopologyFixture::getExpectedPixels($quarters, $sheet))
      ->and(array_values(array_filter($world->operations[0]['value']['layers'],
        static fn(array $layer): bool => $layer['kind'] === 'tiles')))
      ->toBe([['id' => 'tiles:backing', 'layer' => -99, 'kind' => 'tiles'], ['id' => 'tiles:ground', 'layer' => -98, 'kind' => 'tiles']])
      ->and(array_column(array_filter($upperOnly->operations[0]['value']['layers'],
        static fn(array $layer): bool => $layer['kind'] === 'tiles'), 'id'))->toBe(['tiles:ground']);
    // CPU interpretation of the wire pieces, not a native-renderer acceptance test.
    foreach ($upperPixels as $y => $row) {
      foreach ($row as $x => $pixel) {
        expect($lowerPixels[$y][$x][3])->toBe(255);
        if ($pixel[3] === 0) { expect($lowerPixels[$y][$x])->not->toBe($pixel); }
      }
    }
    expect(array_unique(array_column(array_merge(...$upperPixels), 3)))->toContain(0, 128, 255)
      ->and(TileId::getShape(AutotileShape::resolveLayer($upper->tiles)[1][1]))->toBe($shape);
    // Even the same kind on another layer must not supply a missing edge or diagonal.
    $sameKindLower = AutotileTopologyFixture::createLayer('backing', 1,
      array_fill(0, 3, array_fill(0, 3, TileId::getAutotileId(16, 0))));
    $sameKindWorld = AutotileTopologyFixture::createWorld($root, [$sameKindLower, $upper]);
    expect(AutotileTopologyFixture::getCellPieces($sameKindWorld, 'tiles:ground', 1, 1))->toBe($upperPieces);
  })->with($autotileCorners);
