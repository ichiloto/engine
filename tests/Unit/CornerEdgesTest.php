<?php

use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTerrainKinds;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Tilesets\CornerSheet;
use Ichiloto\Engine\Rendering\Tilesets\CornerShape;
use Ichiloto\Engine\Rendering\Tilesets\TileComposer;
use Ichiloto\Engine\Rendering\Tilesets\TileCoverage;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TilePiece;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;

/** Synthetic corner-edged terrain: 8-pixel tiles, every pixel naming its case, phase and quarter. */
final class CornerEdgesFixture
{
  public const int SIZE = 8;
  public const int GRASS = 2816;
  public const int SAND = 2864;
  public const int WATER = 2096;
  public const string WATER_SHEET = 'Graphics/Water.corners.png';
  public const string SAND_SHEET = 'Graphics/Sand.corners.png';

  /** A whole-pixel PNG writer, so the fixture needs no GD. */
  public static function writePng(string $path, int $width, int $height, callable $pixel): void
  {
    $bytes = '';
    for ($y = 0; $y < $height; $y++) {
      $bytes .= "\0";
      for ($x = 0; $x < $width; $x++) { $bytes .= pack('C4', ...$pixel($x, $y)); }
    }
    $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    is_dir(dirname($path)) || mkdir(dirname($path), 0700, true);
    file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
      . $chunk('IDAT', gzcompress($bytes)) . $chunk('IEND', ''));
  }

  /**
   * A corner sheet pixel: opaque where its quarter's corner holds the kind,
   * and across the middle of a diagonal case, so two cells touching at a
   * corner join; colour names the case, phase and the tile's quarter.
   *
   * @return array{int, int, int, int}
   */
  public static function getCornerPixel(int $x, int $y): array
  {
    $phase = intdiv($x, CornerSheet::COLUMNS * self::SIZE);
    $column = intdiv($x % (CornerSheet::COLUMNS * self::SIZE), self::SIZE);
    $row = intdiv($y, self::SIZE);
    $case = array_search([$column, $row], CornerShape::LAYOUT, true);
    [$px, $py] = [$x % self::SIZE, $y % self::SIZE];
    $quarter = intdiv($py, self::SIZE / 2) * 2 + intdiv($px, self::SIZE / 2);
    $joins = match ($case) {
      CornerShape::TOP_LEFT | CornerShape::BOTTOM_RIGHT => in_array([$px, $py], [[3, 4], [4, 3]], true),
      CornerShape::TOP_RIGHT | CornerShape::BOTTOM_LEFT => in_array([$px, $py], [[3, 3], [4, 4]], true),
      default => false,
    };
    $opaque = (($case >> $quarter) & 1) === 1 || $joins;
    return [$case * 16 + $quarter, 40 + $phase * 60, 200, $opaque ? 255 : 0];
  }

  public static function createRoot(int $phases = 3): string
  {
    $root = createTestDirectory('ichiloto-corner-edges-');
    foreach (['A1', 'A2'] as $sheet) {
      self::writePng("{$root}/Graphics/{$sheet}.png", 16 * self::SIZE, 12 * self::SIZE, static fn(): array => [90, 140, 60, 255]);
    }
    self::writePng($root . '/' . self::WATER_SHEET, $phases * 4 * self::SIZE, 4 * self::SIZE, self::getCornerPixel(...));
    self::writePng($root . '/' . self::SAND_SHEET, 4 * self::SIZE, 4 * self::SIZE, self::getCornerPixel(...));
    return $root;
  }

  /** @param array<string, mixed> $extra Extra tileset entries. */
  public static function createTileset(bool $corners = true, bool $sandCorners = false, array $extra = []): Tileset
  {
    return Tileset::fromArray('corners', [
      'name' => 'Corners',
      'sheets' => ['A1' => 'Graphics/A1.png', 'A2' => 'Graphics/A2.png'],
      'kinds' => [
        'grass' => ['name' => 'Grass', 'layer' => 'terrain', 'glyph' => '<fg=green>.</>', 'art' => ['layer' => 'ground', 'tile' => self::GRASS],
          'passage' => 'walk', 'encounters' => 'grassland', 'atlas' => 'ground'],
        'sand' => ['name' => 'Sand', 'layer' => 'terrain', 'glyph' => '<fg=yellow>:</>', 'art' => ['layer' => 'ground', 'tile' => self::SAND,
          ...($sandCorners ? ['corners' => self::SAND_SHEET] : [])], 'passage' => 'walk', 'atlas' => 'ground'],
        'water' => ['name' => 'Water', 'layer' => 'terrain', 'glyph' => '<fg=blue>~</>', 'art' => ['layer' => 'water', 'tile' => self::WATER,
          ...($corners ? ['corners' => self::WATER_SHEET] : [])], 'passage' => 'solid', 'atlas' => 'water', 'keeps' => ['ground']],
      ],
      ...$extra,
    ]);
  }

  /**
   * @param list<string> $rows '~' water, ':' sand, anything else grass, all on the terrain layer's glyphs.
   * @param array<string, string> $owners
   */
  public static function createWorld(string $root, Tileset $tileset, array $rows, array $owners = [], string $waterLayer = 'water'): PresentationWorld
  {
    $grid = static fn(callable $cell): string => implode("\n", array_map(static fn(string $row): string
      => implode(' ', array_map($cell, str_split($row))), $rows));
    $glyphs = implode("\n", $rows);
    $layers = [new MapTileLayer('ground', 1, 'corners/ground', $grid(static fn(string $c): int => match ($c) {
      ':' => self::SAND, '~' => $waterLayer === 'ground' ? self::WATER : self::GRASS, default => self::GRASS }))];
    if ($waterLayer !== 'ground') {
      $layers[] = new MapTileLayer($waterLayer, 2, "corners/{$waterLayer}", $grid(static fn(string $c): int => $c === '~' ? self::WATER : 0));
    }
    return PresentationWorld::getFromLayers(new MapLayerSet([new MapLayer('terrain', 1, false, 'corners', $glyphs)]), 'corners',
      new MapGraphics($tileset, $layers, owners: $owners), $root);
  }

  /** @return array<int, array<int, int>> Catalog tile index by row and column of one world tiles layer. */
  public static function getLayerCells(PresentationWorld $world, string $layerId): array
  {
    $cells = [];
    foreach ($world->operations as $operation) {
      if ($operation['op'] !== 'worldTiles' || $operation['layerId'] !== $layerId) { continue; }
      foreach ($operation['rows'] as $row) {
        foreach ($row['cells'] as $cell) { $cells[$row['row']][$cell['column']] = $cell['tile']; }
      }
    }
    return $cells;
  }

  /**
   * The wire tiles of the listed layers drawn on the CPU, one frame, as the
   * opaque pixels by row: an interpretation of the pieces, not a native test.
   *
   * @param list<string> $layerIds
   * @return list<list<bool>>
   */
  public static function rasterize(PresentationWorld $world, array $layerIds, int $width, int $height, int $frame = 0): array
  {
    $tileset = $world->operations[0]['value']['tileset'];
    $sheets = [];
    foreach ($tileset['sheets'] as $index => $path) { $sheets[$index] = $path; }
    $pixels = array_fill(0, $height * self::SIZE, array_fill(0, $width * self::SIZE, false));
    foreach ($layerIds as $layerId) {
      foreach (self::getLayerCells($world, $layerId) as $y => $row) {
        foreach ($row as $x => $index) {
          $frames = $tileset['tiles'][$index]['frames'];
          foreach ($frames[$frame % count($frames)] as $piece) {
            if (!str_contains($sheets[$piece['sheet']], 'corners')) { continue; }
            for ($py = 0; $py < $piece['height']; $py++) {
              for ($px = 0; $px < $piece['width']; $px++) {
                if (self::getCornerPixel($piece['x'] + $px, $piece['y'] + $py)[3] === 255) {
                  $pixels[$y * self::SIZE + $piece['top'] + $py][$x * self::SIZE + $piece['left'] + $px] = true;
                }
              }
            }
          }
        }
      }
    }
    return $pixels;
  }

  /** The opaque pixels 4-connected to a starting pixel. */
  public static function fill(array $pixels, int $x, int $y): array
  {
    $seen = [];
    $queue = [[$x, $y]];
    while ($queue !== []) {
      [$x, $y] = array_pop($queue);
      if (isset($seen[$y][$x]) || !($pixels[$y][$x] ?? false)) { continue; }
      $seen[$y][$x] = true;
      array_push($queue, [$x + 1, $y], [$x - 1, $y], [$x, $y + 1], [$x, $y - 1]);
    }
    return $seen;
  }
}

it('lays the 16 corner cases out once each as one seamless 4 x 4 picture', function () {
  $byPosition = [];
  foreach (CornerShape::LAYOUT as $case => [$column, $row]) { $byPosition[$row][$column] = $case; }
  expect(array_keys(CornerShape::LAYOUT))->toBe(range(0, 15))
    ->and(count(array_unique(array_map(static fn(array $at): string => implode(',', $at), CornerShape::LAYOUT))))->toBe(16)
    ->and(CornerShape::LAYOUT[0])->toBe([0, 0]);
  for ($row = 0; $row < 4; $row++) {
    for ($column = 0; $column < 4; $column++) {
      $case = $byPosition[$row][$column];
      $right = $byPosition[$row][($column + 1) % 4];
      $below = $byPosition[($row + 1) % 4][$column];
      // A tile's right corners are its right neighbour's left corners, and its bottom corners are the next row's top.
      expect([($case >> 1) & 1, ($case >> 3) & 1])->toBe([$right & 1, ($right >> 2) & 1])
        ->and([($case >> 2) & 1, ($case >> 3) & 1])->toBe([$below & 1, ($below >> 1) & 1]);
    }
  }
});

it('chooses each corner tile from the four cells around the corner, for all 16 cases', function (int $case) {
  $layer = [[0, 0], [0, 0]];
  foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as $bit => [$x, $y]) {
    if (($case >> $bit) & 1) { $layer[$y][$x] = TileId::getAutotileId(TileId::getKind(CornerEdgesFixture::WATER), 7); }
  }
  $resolved = CornerShape::resolveLayer($layer, [CornerEdgesFixture::WATER]);
  // The corner between the four cells: each cell's quarter that faces it.
  $facing = [[0, 0, 3], [1, 0, 2], [0, 1, 1], [1, 1, 0]];
  $sheet = new CornerSheet('water', CornerEdgesFixture::WATER, CornerEdgesFixture::WATER_SHEET, 1);
  $tile = array_fill(0, CornerEdgesFixture::SIZE, array_fill(0, CornerEdgesFixture::SIZE, null));
  foreach ($facing as [$x, $y, $quarter]) {
    $cases = $resolved[$y][$x][CornerEdgesFixture::WATER] ?? 0;
    expect(($cases >> ($quarter * 4)) & 0xF)->toBe($case);
    if ($cases === 0) { continue; }
    foreach (TileComposer::composeCorners($sheet, $cases, CornerEdgesFixture::SIZE)[0] as $piece) {
      if ($piece->left !== ($quarter % 2) * 4 || $piece->top !== intdiv($quarter, 2) * 4) { continue; }
      for ($py = 0; $py < 4; $py++) {
        for ($px = 0; $px < 4; $px++) {
          // Reassemble the tile centred on the corner from the four cells' quarters.
          $tile[$y * 4 + $py][$x * 4 + $px] = CornerEdgesFixture::getCornerPixel($piece->x + $px, $piece->y + $py);
        }
      }
    }
  }
  [$column, $row] = CornerShape::LAYOUT[$case];
  $expected = [];
  for ($py = 0; $py < CornerEdgesFixture::SIZE; $py++) {
    for ($px = 0; $px < CornerEdgesFixture::SIZE; $px++) {
      $expected[$py][$px] = CornerEdgesFixture::getCornerPixel($column * CornerEdgesFixture::SIZE + $px, $row * CornerEdgesFixture::SIZE + $py);
    }
  }
  expect($case === 0 ? $resolved : $tile)->toBe($case === 0 ? [] : $expected);
})->with(array_map(static fn(int $case): array => [$case], range(0, 15)));

it('continues each edge cell beyond the map, so no edge closes along its border', function () {
  $water = CornerEdgesFixture::WATER;
  $full = CornerShape::resolveLayer([[$water, $water], [$water, $water]], [$water]);
  $border = CornerShape::resolveLayer([[$water, 0, 0], [0, 0, 0]], [$water]);
  expect($full)->toBe(array_fill(0, 2, array_fill(0, 2, [$water => 0xFFFF])))
    // The corner cell's outer corners hold only water, as its row and column continue.
    ->and($border[0][0][$water])->toBe(15 | (1 | 4) << 4 | (1 | 2) << 8 | 1 << 12)
    ->and($border[0][1][$water])->toBe((1 | 4) | 0 << 4 | 1 << 8 | 0 << 12)
    ->and($border[1][1][$water])->toBe(1)
    ->and(array_keys($border[0]))->toBe([0, 1])
    ->and(isset($border[0][2]))->toBeFalse();
});

it('keeps a one-cell diagonal stream joined through every corner where its cells touch', function () {
  $root = CornerEdgesFixture::createRoot();
  $rows = ['......', '.~....', '..~...', '...~..', '..~...', '......'];
  $world = CornerEdgesFixture::createWorld($root, CornerEdgesFixture::createTileset(), $rows);
  $pixels = CornerEdgesFixture::rasterize($world, ['tiles:water:corners:water'], 6, 6);
  $half = CornerEdgesFixture::SIZE / 2;
  $joined = CornerEdgesFixture::fill($pixels, 1 * 8 + $half, 1 * 8 + $half);
  foreach ([[2, 2], [3, 3], [2, 4]] as [$x, $y]) {
    expect(isset($joined[$y * 8 + $half][$x * 8 + $half]))->toBeTrue("cell ({$x}, {$y}) is joined");
  }
  // Grass cells beside the stream show its edge too, and stay apart from it at their own centres.
  expect(CornerEdgesFixture::getLayerCells($world, 'tiles:water:corners:water')[1][2] ?? null)->not->toBeNull()
    ->and($pixels[1 * 8 + $half][2 * 8 + $half])->toBeFalse();
});

it('uploads corner-edged cells in their own layer after their tile layer, animated in A1 water\'s cycle', function () {
  $root = CornerEdgesFixture::createRoot();
  $world = CornerEdgesFixture::createWorld($root, CornerEdgesFixture::createTileset(), ['....', '.~~.', '.~~.', '....'], ['water' => 'terrain']);
  $value = $world->operations[0]['value'];
  $tiles = array_values(array_filter($value['layers'], static fn(array $layer): bool => $layer['kind'] === 'tiles'));
  $cells = CornerEdgesFixture::getLayerCells($world, 'tiles:water:corners:water');
  $centre = $value['tileset']['tiles'][$cells[1][1]];
  $water = array_search(CornerEdgesFixture::WATER_SHEET, $value['tileset']['sheets'], true);
  expect($tiles)->toBe([['id' => 'tiles:ground', 'layer' => -99, 'kind' => 'tiles'],
      ['id' => 'tiles:water:corners:water', 'layer' => -98, 'kind' => 'tiles']])
    ->and(CornerEdgesFixture::getLayerCells($world, 'tiles:water'))->toBe([])
    ->and(array_map(count(...), $cells))->toBe([0 => 4, 1 => 4, 2 => 4, 3 => 4])
    ->and($value['tileset']['sheets'])->toBe(['Graphics/A1.png', 'Graphics/A2.png', CornerEdgesFixture::WATER_SHEET])
    ->and($world->animated)->toBeTrue()
    ->and(count($centre['frames']))->toBe(4)
    // Each frame's quarters come from phases 0, 1, 2, 1 of the sheet.
    ->and(array_map(static fn(array $frame): int => intdiv($frame[0]['x'], 4 * CornerEdgesFixture::SIZE), $centre['frames']))->toBe(TileComposer::WATER_FRAMES)
    ->and(array_unique(array_column(array_merge(...$centre['frames']), 'sheet')))->toBe([$water])
    ->and(array_column(array_filter($world->getOperations(true)[0]['value']['layers'],
      static fn(array $layer): bool => $layer['kind'] === 'tiles'), 'coversLayerId', 'id'))
    ->toBe(['tiles:water:corners:water' => 'map:terrain']);
});

it('draws a still corner sheet in one frame and kinds sharing a tile layer in kind order, each over those before', function () {
  $root = CornerEdgesFixture::createRoot(phases: 1);
  $tileset = CornerEdgesFixture::createTileset(sandCorners: true);
  $world = CornerEdgesFixture::createWorld($root, $tileset, ['......', '.::~~.', '.:~~:.', '......'], waterLayer: 'ground');
  $value = $world->operations[0]['value'];
  $sandCells = CornerEdgesFixture::getLayerCells($world, 'tiles:ground:corners:sand');
  expect(array_column(array_filter($value['layers'], static fn(array $layer): bool => $layer['kind'] === 'tiles'), 'layer', 'id'))
    ->toBe(['tiles:ground' => -99, 'tiles:ground:corners:sand' => -99, 'tiles:ground:corners:water' => -99])
    ->and($world->animated)->toBeFalse()
    ->and(count($value['tileset']['tiles'][$sandCells[1][1]]['frames']))->toBe(1)
    // Neither corner-edged kind draws its own cells as an autotile; grass still does, beneath both.
    ->and(array_keys(CornerEdgesFixture::getLayerCells($world, 'tiles:ground')[1]))->toBe([0, 5]);
});

it('draws a corner-edged kind in the above band when its autotile is drawn above characters', function () {
  $root = CornerEdgesFixture::createRoot();
  $world = CornerEdgesFixture::createWorld($root, CornerEdgesFixture::createTileset(extra: ['above' => [CornerEdgesFixture::WATER]]), ['.~.']);
  expect(array_column(array_filter($world->operations[0]['value']['layers'], static fn(array $layer): bool => $layer['kind'] === 'tiles'), 'layer', 'id'))
    ->toBe(['tiles:ground' => -99, 'tiles:water:above:corners:water' => 902]);
});

it('leaves terminal cells, kinds, collision and encounters exactly as without corner edges', function () {
  $root = CornerEdgesFixture::createRoot();
  $rows = ['.....', '.~~~.', '.~.~.', '.....'];
  $plain = CornerEdgesFixture::createTileset(corners: false);
  $cornered = CornerEdgesFixture::createTileset();
  $terminal = static fn(PresentationWorld $world): array => array_values(array_filter($world->operations,
    static fn(array $operation): bool => $operation['op'] === 'worldRows'));
  $pieces = static fn(Tileset $tileset): array => array_map(static fn($kind): array => [$kind->piece->glyphs, $kind->piece->tiles,
    $kind->piece->keeps, $kind->collision, $kind->encounters, $kind->passage], $tileset->kinds);
  expect($terminal(CornerEdgesFixture::createWorld($root, $cornered, $rows)))->toBe($terminal(CornerEdgesFixture::createWorld($root, $plain, $rows)))
    ->and($pieces($cornered))->toBe($pieces($plain))
    ->and((new MapTerrainKinds($cornered))->composeDictionary([]))->toBe((new MapTerrainKinds($plain))->composeDictionary([]))
    ->and($cornered->kinds['water']->cornerEdged)->toBeTrue()
    ->and($plain->kinds['water']->cornerEdged)->toBeFalse();
});

it('uploads a map whose kinds draw no corner edges byte for byte as before', function () {
  $root = CornerEdgesFixture::createRoot();
  $plain = CornerEdgesFixture::createTileset(corners: false);
  $cornered = CornerEdgesFixture::createTileset();
  $grassAndSand = ['.:..', '..:.', '....'];
  $withWater = ['.:~.', '.~~.', '....'];
  $operations = static fn(PresentationWorld $world, array $only = []): string => json_encode(array_values(array_filter(
    $world->getOperations(true, true), static fn(array $operation): bool => $only === [] || in_array($operation['layerId'] ?? '', $only, true))));
  $plainWorld = CornerEdgesFixture::createWorld($root, $plain, $withWater);
  $corneredWorld = CornerEdgesFixture::createWorld($root, $cornered, $withWater);
  expect($operations(CornerEdgesFixture::createWorld($root, $cornered, $grassAndSand)))
    ->toBe($operations(CornerEdgesFixture::createWorld($root, $plain, $grassAndSand)))
    // Beside corner-edged water, the grass and sand autotiles still upload the same rows and pieces.
    ->and(array_map(static fn(array $row): array => array_map(static fn(int $index): array => $plainWorld->operations[0]['value']['tileset']['tiles'][$index], $row),
      CornerEdgesFixture::getLayerCells($plainWorld, 'tiles:ground')))
    ->toBe(array_map(static fn(array $row): array => array_map(static fn(int $index): array => $corneredWorld->operations[0]['value']['tileset']['tiles'][$index], $row),
      CornerEdgesFixture::getLayerCells($corneredWorld, 'tiles:ground')));
});

it('shows the terminal glyphs of a corner-edged kind whose corner sheet cannot be drawn, never its autotile', function () {
  $root = CornerEdgesFixture::createRoot();
  CornerEdgesFixture::writePng($root . '/' . CornerEdgesFixture::WATER_SHEET, 50, 30, static fn(): array => [0, 0, 255, 255]);
  $world = CornerEdgesFixture::createWorld($root, CornerEdgesFixture::createTileset(), ['.~.', '.~.']);
  expect(array_column(array_filter($world->operations[0]['value']['layers'], static fn(array $layer): bool => $layer['kind'] === 'tiles'), 'id'))
    ->toBe(['tiles:ground'])
    ->and($world->operations[0]['value']['tileset']['sheets'])->toBe(['Graphics/A1.png', 'Graphics/A2.png']);
});

it('refuses a world whose corner sheets would pass the renderer\'s sheet budget', function () {
  $root = CornerEdgesFixture::createRoot();
  $kinds = [];
  $rows = [];
  for ($index = 0; $index < PresentationWorld::MAX_SHEETS - 1; $index++) {
    $kinds["pool-{$index}"] = ['name' => "Pool {$index}", 'layer' => 'terrain', 'glyph' => '<fg=blue>' . chr(65 + $index) . '</>',
      'art' => ['layer' => 'water', 'tile' => TileId::getAutotileId(16 + $index, 0), 'corners' => CornerEdgesFixture::WATER_SHEET],
      'passage' => 'solid', 'atlas' => 'water'];
    $rows[] = TileId::getAutotileId(16 + $index, 0);
  }
  $tileset = Tileset::fromArray('budget', ['name' => 'Budget', 'sheets' => ['A1' => 'Graphics/A1.png', 'A2' => 'Graphics/A2.png'], 'kinds' => $kinds]);
  $glyphs = new MapLayerSet([new MapLayer('terrain', 1, false, 'budget', str_repeat('.', count($rows)))]);
  $graphics = new MapGraphics($tileset, [new MapTileLayer('water', 1, 'budget/water', implode(' ', $rows))]);
  expect(fn() => PresentationWorld::getFromLayers($glyphs, 'budget', $graphics, $root))
    ->toThrow(InvalidArgumentException::class, '16-sheet tileset budget');
});

it('reads a kind\'s corner sheet as an asset-relative PNG and refuses anything else', function (mixed $corners, ?string $message) {
  $read = static fn() => Tileset::fromArray('kinds', ['name' => 'Kinds', 'sheets' => ['A1' => 'Graphics/A1.png'], 'kinds' => [
    'water' => ['name' => 'Water', 'layer' => 'terrain', 'glyph' => '<fg=blue>~</>', 'passage' => 'solid', 'atlas' => 'water',
      'art' => ['layer' => 'water', 'tile' => CornerEdgesFixture::WATER, 'corners' => $corners]]]]);
  if ($message === null) {
    expect($read()->kinds['water']->art)->toBe(['layer' => 'water', 'tile' => CornerEdgesFixture::WATER, 'corners' => $corners]);
    return;
  }
  expect($read)->toThrow(InvalidArgumentException::class, $message);
})->with([
  'png' => ['Graphics/Tilesets/Water.corners.png', null],
  'not png' => ['Graphics/Water.webp', 'corner sheet'],
  'not a path' => [42, 'corner sheet'],
  'parent traversal' => ['../Water.png', 'relative'],
  'absolute' => ['/Water.png', 'relative'],
]);

it('refuses unknown art keys beside a corner sheet', function () {
  expect(fn() => Tileset::fromArray('kinds', ['name' => 'Kinds', 'sheets' => ['A1' => 'Graphics/A1.png'], 'kinds' => [
    'water' => ['name' => 'Water', 'layer' => 'terrain', 'glyph' => '<fg=blue>~</>', 'passage' => 'solid', 'atlas' => 'water',
      'art' => ['layer' => 'water', 'tile' => CornerEdgesFixture::WATER, 'corners' => 'Graphics/W.png', 'phases' => 3]]]]))
    ->toThrow(InvalidArgumentException::class, "optional 'corners'");
});

it('reads a corner sheet\'s phases from its image and refuses other shapes', function () {
  $root = CornerEdgesFixture::createRoot();
  $kind = CornerEdgesFixture::createTileset()->kinds['water'];
  expect(CornerSheet::read($kind, $root, CornerEdgesFixture::SIZE)->phases)->toBe(3);
  CornerEdgesFixture::writePng($root . '/' . CornerEdgesFixture::WATER_SHEET, 4 * CornerEdgesFixture::SIZE, 4 * CornerEdgesFixture::SIZE,
    CornerEdgesFixture::getCornerPixel(...));
  expect(CornerSheet::read($kind, $root, CornerEdgesFixture::SIZE)->phases)->toBe(1);
  foreach ([[8, 32], [64, 32], [32, 24]] as [$width, $height]) {
    CornerEdgesFixture::writePng($root . '/' . CornerEdgesFixture::WATER_SHEET, $width, $height, static fn(): array => [0, 0, 0, 255]);
    expect(fn() => CornerSheet::read($kind, $root, CornerEdgesFixture::SIZE))->toThrow(RuntimeException::class, 'corner sheet');
  }
});

it('inspects a corner-edged cell by its corner tiles, never by its autotile', function () {
  if (!function_exists('imagecreatefrompng')) {
    $this->markTestSkipped('Tile pixel inspection needs PHP GD.');
  }
  $root = CornerEdgesFixture::createRoot();
  $coverage = new TileCoverage(CornerEdgesFixture::createTileset(), $root);
  $quarter = CornerEdgesFixture::SIZE ** 2 / 4;
  expect($coverage->findUncovered([CornerEdgesFixture::WATER]))->toBe(['tileSize' => 8, 'uncoveredPixels' => 64, 'diagnostic' => null])
    ->and($coverage->findUncovered([CornerEdgesFixture::WATER], [[CornerEdgesFixture::WATER, 0xFFFF]]))
    ->toBe(['tileSize' => 8, 'uncoveredPixels' => 0, 'diagnostic' => null])
    // A lone cell: each quarter faces a corner holding only this cell, so only its own quarter of each corner tile is water.
    ->and($coverage->findUncovered([], [[CornerEdgesFixture::WATER, 8 | 4 << 4 | 2 << 8 | 1 << 12]]))
    ->toBe(['tileSize' => 8, 'uncoveredPixels' => 0, 'diagnostic' => null])
    ->and($coverage->findUncovered([], [[CornerEdgesFixture::WATER, 1 | 2 << 4]])['uncoveredPixels'])->toBe(4 * $quarter)
    ->and($coverage->findUncovered([CornerEdgesFixture::GRASS], [[CornerEdgesFixture::WATER, 1]]))
    ->toBe(['tileSize' => 8, 'uncoveredPixels' => 0, 'diagnostic' => null])
    ->and(fn() => $coverage->findUncovered([], [[CornerEdgesFixture::GRASS, 1]]))->toThrow(InvalidArgumentException::class);
});
