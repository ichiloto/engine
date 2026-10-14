<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Tilesets\TileComposer;
use Ichiloto\Engine\Rendering\Tilesets\TileCoverage;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetSheet;
use Ichiloto\Engine\Util\Debug;

/** @return list<bool> */
function createTileCoverageMask(int $size, callable $isVisible): array
{
  $mask = [];
  for ($y = 0; $y < $size; $y++) {
    for ($x = 0; $x < $size; $x++) { $mask[] = $isVisible($x, $y); }
  }
  return $mask;
}

function writeTileCoveragePng(string $path, TilesetSheet $sheet, int $size, callable $paint, bool $palette = false): void
{
  if (!function_exists('imagecreatefrompng')) {
    test()->markTestSkipped('Optional GD PNG inspection is unavailable.');
  }
  $width = $sheet->getColumns() * $size;
  $height = $sheet->getRows() * $size;
  $image = $palette ? imagecreate($width, $height) : imagecreatetruecolor($width, $height);
  try {
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $transparent = imagecolorallocatealpha($image, 10, 20, 30, 127);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $transparent);
    $paint($image);
    // Uncompressed PNGs keep equal dimensions equal in byte size during replacement tests.
    expect(imagepng($image, $path, 0))->toBeTrue();
  } finally {
    unset($image);
  }
}

/** @param list<bool> $mask */
function paintTileCoverageMask(GdImage $image, int $left, int $top, int $size, array $mask): void
{
  $visible = imagecolorallocatealpha($image, 100, 150, 200, 126);
  $transparent = imagecolorallocatealpha($image, 10, 20, 30, 127);
  foreach ($mask as $index => $pixel) {
    imagesetpixel($image, $left + $index % $size, $top + intdiv($index, $size), $pixel ? $visible : $transparent);
  }
}

/** @param list<list<bool>> $masks */
function paintTileCoverageFrames(GdImage $image, int $id, int $size, array $masks): void
{
  $visible = imagecolorallocatealpha($image, 100, 150, 200, 126);
  $transparent = imagecolorallocatealpha($image, 10, 20, 30, 127);
  $frames = TileComposer::compose($id, $size);
  expect($frames)->toHaveCount(count($masks));
  foreach ($frames as $index => $pieces) {
    foreach ($pieces as $piece) {
      for ($y = 0; $y < $piece->height; $y++) {
        for ($x = 0; $x < $piece->width; $x++) {
          $pixel = $masks[$index][($piece->top + $y) * $size + $piece->left + $x];
          imagesetpixel($image, $piece->x + $x, $piece->y + $y, $pixel ? $visible : $transparent);
        }
      }
    }
  }
}

/**
 * Small exhaustive oracle, deliberately independent of the production intersection shortcut.
 * @param list<list<list<bool>>> $layers
 * @return array{uncoveredPixels: int, maxFrameHoles: int}
 */
function countTileCoverageCartesianExposure(array $layers, int $size): array
{
  $combinations = [array_fill(0, $size * $size, false)];
  foreach ($layers as $frames) {
    $next = [];
    foreach ($combinations as $covered) {
      foreach ($frames as $frame) {
        $union = $covered;
        foreach ($frame as $index => $pixel) { $union[$index] = $union[$index] || $pixel; }
        $next[] = $union;
      }
    }
    $combinations = $next;
  }
  $exposed = array_fill(0, $size * $size, false);
  $maximum = 0;
  foreach ($combinations as $covered) {
    $holes = 0;
    foreach ($covered as $index => $pixel) {
      if (!$pixel) { $exposed[$index] = true; $holes++; }
    }
    $maximum = max($maximum, $holes);
  }
  return ['uncoveredPixels' => count(array_filter($exposed)), 'maxFrameHoles' => $maximum];
}

beforeEach(function () {
  $this->fixtureRoot = createTestDirectory('ichiloto-tile-coverage-');
  $this->root = $this->fixtureRoot . '/assets';
  mkdir($this->root);
  $this->logDirectoryProperty = new ReflectionProperty(Debug::class, 'logDirectory');
  $this->previousLogDirectory = $this->logDirectoryProperty->getValue();
  $this->logDirectoryProperty->setValue(null, $this->fixtureRoot . '/logs');
});

afterEach(function () {
  $this->logDirectoryProperty->setValue(null, $this->previousLogDirectory);
});

it('reads row-major truecolor and palette alpha 126 as visible and alpha 127 as transparent without rewriting sheets', function (bool $palette) {
  $mask = createTileCoverageMask(4, static fn(int $x, int $y): bool => in_array([$x, $y], [[0, 0], [3, 1], [1, 3]], true));
  $solid = array_fill(0, 16, true);
  writeTileCoveragePng($this->root . '/B.png', TilesetSheet::B, 4, static function (GdImage $image) use ($mask, $solid): void {
    paintTileCoverageMask($image, 0, 0, 4, $solid);
    paintTileCoverageMask($image, 4, 0, 4, $mask);
    paintTileCoverageMask($image, 40, 0, 4, $mask);
  }, $palette);
  writeTileCoveragePng($this->root . '/C.png', TilesetSheet::C, 4,
    static fn(GdImage $image) => paintTileCoverageMask($image, 0, 0, 4, $mask), $palette);
  writeTileCoveragePng($this->root . '/A5.png', TilesetSheet::A5, 4,
    static fn(GdImage $image) => paintTileCoverageMask($image, 28, 60, 4, $mask), $palette);
  $sheets = ['B' => 'B.png', 'C' => 'C.png', 'A5' => 'A5.png'];
  $before = array_map(fn(string $asset): string => file_get_contents($this->root . '/' . $asset), $sheets);
  $reader = new TileCoverage(new Tileset('synthetic', 'Synthetic', $sheets), $this->root);
  foreach ([1, 130, TileId::C, TileId::A5 + 127] as $id) {
    expect($reader->readFrames($id))->toBe(['tileSize' => 4, 'frames' => [$mask], 'diagnostic' => null])
      ->and($reader->findUncovered([$id]))->toBe(['tileSize' => 4, 'uncoveredPixels' => 13, 'diagnostic' => null]);
  }
  expect($reader->readFrames(TileId::EMPTY))->toBe(['tileSize' => 4, 'frames' => [array_fill(0, 16, false)], 'diagnostic' => null])
    ->and(array_map(fn(string $asset): string => file_get_contents($this->root . '/' . $asset), $sheets))->toBe($before);
  $returned = $reader->readFrames(1);
  $returned['frames'][0][0] = false;
  expect($reader->readFrames(1)['frames'])->toBe([$mask]);
})->with(['truecolor' => [false], 'palette' => [true]]);

it('distinguishes transparent tiles and empty stacks from unavailable inspection', function () {
  writeTileCoveragePng($this->root . '/B.png', TilesetSheet::B, 4, static function (GdImage $image): void {});
  $reader = new TileCoverage(new Tileset('synthetic', 'Synthetic', ['B' => 'B.png']), $this->root);
  foreach ([1, TileId::EMPTY] as $id) {
    expect($reader->readFrames($id))->toBe(['tileSize' => 4, 'frames' => [array_fill(0, 16, false)], 'diagnostic' => null]);
  }
  foreach ([[], [TileId::EMPTY], [1], [1, TileId::EMPTY, 1]] as $stack) {
    expect($reader->findUncovered($stack))->toBe(['tileSize' => 4, 'uncoveredPixels' => 16, 'diagnostic' => null]);
  }
});

it('uses the shared quarter geometry for every floor and wall shape', function (string $sheetName, int $kind) {
  $sheet = TilesetSheet::from($sheetName);
  $isVisible = static fn(int $x, int $y): bool => ($x + 2 * $y) % 5 < 2;
  writeTileCoveragePng($this->root . '/sheet.png', $sheet, 4, static function (GdImage $image) use ($isVisible): void {
    $visible = imagecolorallocatealpha($image, 40, 80, 120, 0);
    for ($y = 0; $y < imagesy($image); $y++) {
      for ($x = 0; $x < imagesx($image); $x++) {
        if ($isVisible($x, $y)) { imagesetpixel($image, $x, $y, $visible); }
      }
    }
  });
  $reader = new TileCoverage(new Tileset('synthetic', 'Synthetic', [$sheetName => 'sheet.png']), $this->root);
  for ($shape = 0; $shape < TileId::SHAPES; $shape++) {
    $id = TileId::getAutotileId($kind, $shape);
    $expected = array_fill(0, 16, false);
    foreach (TileComposer::compose($id, 4)[0] as $piece) {
      for ($y = 0; $y < $piece->height; $y++) {
        for ($x = 0; $x < $piece->width; $x++) {
          $expected[($piece->top + $y) * 4 + $piece->left + $x] = $isVisible($piece->x + $x, $piece->y + $y);
        }
      }
    }
    expect($reader->readFrames($id))->toBe(['tileSize' => 4, 'frames' => [$expected], 'diagnostic' => null]);
  }
})->with(['A2 floor' => ['A2', 17], 'A3 wall' => ['A3', 54], 'A4 floor' => ['A4', 83], 'A4 wall' => ['A4', 89]]);

it('unions A2 table legs with overlapping tops without transparent pieces erasing coverage', function (int $shape, int $sourceX, int $sourceY) {
  writeTileCoveragePng($this->root . '/A2.png', TilesetSheet::A2, 8,
    static function (GdImage $image) use ($sourceX, $sourceY): void {
      $visible = imagecolorallocatealpha($image, 70, 110, 150, 126);
      imagesetpixel($image, $sourceX, 13, $visible);
      imagesetpixel($image, $sourceX, 15, $visible);
      imagesetpixel($image, $sourceX + 1, $sourceY, $visible);
    });
  $id = TileId::A2 + $shape;
  $sheets = ['A2' => 'A2.png'];
  $table = new TileCoverage(new Tileset('table', 'Table', $sheets, tables: [TileId::A2]), $this->root);
  $normal = new TileCoverage(new Tileset('normal', 'Normal', $sheets), $this->root);
  $expected = createTileCoverageMask(8, static fn(int $x, int $y): bool => in_array([$x, $y], [[0, 5], [0, 7], [1, 6]], true));
  expect($table->readFrames($id))->toBe(['tileSize' => 8, 'frames' => [$expected], 'diagnostic' => null])
    ->and($table->findUncovered([$id]))->toBe(['tileSize' => 8, 'uncoveredPixels' => 61, 'diagnostic' => null])
    ->and($normal->readFrames($id)['frames'])->toBe([createTileCoverageMask(8,
      static fn(int $x, int $y): bool => $x === 1 && $y === 4)]);
})->with(['isolated top' => [47, 0, 4], 'mirrored leg' => [15, 8, 4], 'lower top' => [28, 8, 20]]);

it('finds A1 water gaps in later frames and counts distinct exposed pixels rather than the largest single-frame hole', function () {
  $solid = array_fill(0, 16, true);
  $firstGap = $secondGap = $solid;
  $firstGap[0] = false;
  $secondGap[15] = false;
  $frames = [$solid, $firstGap, $secondGap, $firstGap];
  $id = TileId::getAutotileId(0, 47);
  writeTileCoveragePng($this->root . '/A1.png', TilesetSheet::A1, 4,
    static fn(GdImage $image) => paintTileCoverageFrames($image, $id, 4, $frames));
  $reader = new TileCoverage(new Tileset('water', 'Water', ['A1' => 'A1.png']), $this->root);
  expect($reader->readFrames($id))->toBe(['tileSize' => 4, 'frames' => $frames, 'diagnostic' => null])
    ->and(countTileCoverageCartesianExposure([$frames], 4))->toBe(['uncoveredPixels' => 2, 'maxFrameHoles' => 1])
    ->and($reader->findUncovered([$id]))->toBe(['tileSize' => 4, 'uncoveredPixels' => 2, 'diagnostic' => null]);
});

it('combines complementary static layers and a complete substrate without double counting or depending on draw order', function () {
  $left = createTileCoverageMask(4, static fn(int $x, int $y): bool => $x < 2);
  $right = array_map(static fn(bool $pixel): bool => !$pixel, $left);
  $solid = array_fill(0, 16, true);
  writeTileCoveragePng($this->root . '/B.png', TilesetSheet::B, 4, static function (GdImage $image) use ($left, $right, $solid): void {
    paintTileCoverageMask($image, 4, 0, 4, $left);
    paintTileCoverageMask($image, 8, 0, 4, $right);
    paintTileCoverageMask($image, 12, 0, 4, $solid);
  });
  $reader = new TileCoverage(new Tileset('layers', 'Layers', ['B' => 'B.png']), $this->root);
  foreach ([[1], [2], [1, 1], [TileId::EMPTY, 1]] as $stack) {
    expect($reader->findUncovered($stack))->toBe(['tileSize' => 4, 'uncoveredPixels' => 8, 'diagnostic' => null]);
  }
  foreach ([[1, 2], [2, 1], [TileId::EMPTY, 2, 1], [3, 1], [2, 3], [3]] as $stack) {
    expect($reader->findUncovered($stack))->toBe(['tileSize' => 4, 'uncoveredPixels' => 0, 'diagnostic' => null]);
  }
});

it('matches an exhaustive independent cartesian reference across water and waterfall layers', function () {
  $solid = array_fill(0, 16, true);
  $holeA = $holeB = $solid;
  $holeA[0] = false;
  $holeB[1] = false;
  $waterA = TileId::getAutotileId(0, 47);
  $waterB = TileId::getAutotileId(1, 47);
  $waterfall = TileId::getAutotileId(5, 3);
  $frames = [
    $waterA => [$solid, $holeA, $holeB, $holeA],
    $waterB => [$solid, $holeB, $holeA, $holeB],
    $waterfall => [$holeA, $solid, $holeB],
    1 => [$solid],
  ];
  writeTileCoveragePng($this->root . '/A1.png', TilesetSheet::A1, 4,
    static function (GdImage $image) use ($frames, $waterA, $waterB, $waterfall): void {
      foreach ([$waterA, $waterB, $waterfall] as $id) { paintTileCoverageFrames($image, $id, 4, $frames[$id]); }
    });
  writeTileCoveragePng($this->root . '/B.png', TilesetSheet::B, 4,
    static fn(GdImage $image) => paintTileCoverageMask($image, 4, 0, 4, $solid));
  $reader = new TileCoverage(new Tileset('animated', 'Animated', ['A1' => 'A1.png', 'B' => 'B.png']), $this->root);
  foreach ($frames as $id => $expected) {
    expect($reader->readFrames($id))->toBe(['tileSize' => 4, 'frames' => $expected, 'diagnostic' => null]);
  }
  $animated = [$frames[$waterA], $frames[$waterB], $frames[$waterfall]];
  expect(countTileCoverageCartesianExposure($animated, 4))->toBe(['uncoveredPixels' => 2, 'maxFrameHoles' => 1]);
  // Shared-counter frames never expose either pixel; independent choices can expose both at different times.
  for ($counter = 0; $counter < 12; $counter++) {
    $synchronized = [[$frames[$waterA][$counter % 4]], [$frames[$waterB][$counter % 4]], [$frames[$waterfall][$counter % 3]]];
    expect(countTileCoverageCartesianExposure($synchronized, 4)['uncoveredPixels'])->toBe(0);
  }
  foreach ([[], [$waterA], [$waterfall], [$waterA, $waterB], [$waterA, $waterB, $waterfall],
    [$waterfall, $waterB, $waterA], [1, $waterA, $waterB, $waterfall], [$waterA, $waterB, $waterfall, 1]] as $stack) {
    $reference = countTileCoverageCartesianExposure(array_map(static fn(int $id): array => $frames[$id], $stack), 4);
    expect($reader->findUncovered($stack))->toBe(['tileSize' => 4, 'uncoveredPixels' => $reference['uncoveredPixels'], 'diagnostic' => null]);
  }
});

it('keeps existing inspection snapshots stable while a new reader sees same-size same-time replaced pixels', function () {
  $path = $this->root . '/B.png';
  $oldMask = createTileCoverageMask(4, static fn(int $x, int $y): bool => $x === 0 && $y === 1);
  $newMask = createTileCoverageMask(4, static fn(int $x, int $y): bool => $x === 3 && $y === 2);
  writeTileCoveragePng($path, TilesetSheet::B, 4, static fn(GdImage $image) => paintTileCoverageMask($image, 4, 0, 4, $oldMask));
  $oldBytes = file_get_contents($path);
  $oldTime = filemtime($path);
  $tileset = new Tileset('replaceable', 'Replaceable', ['B' => 'B.png']);
  $reader = new TileCoverage($tileset, $this->root);
  $unreadReader = new TileCoverage($tileset, $this->root);
  expect($reader->readFrames(1)['frames'])->toBe([$oldMask])->and(file_get_contents($path))->toBe($oldBytes);
  writeTileCoveragePng($path, TilesetSheet::B, 4, static fn(GdImage $image) => paintTileCoverageMask($image, 4, 0, 4, $newMask));
  expect(touch($path, $oldTime))->toBeTrue();
  clearstatcache(true, $path);
  $newBytes = file_get_contents($path);
  expect(strlen($newBytes))->toBe(strlen($oldBytes))->and($newBytes)->not->toBe($oldBytes)
    ->and(filemtime($path))->toBe($oldTime)
    ->and($reader->readFrames(1)['frames'])->toBe([$oldMask])
    ->and($unreadReader->readFrames(1)['frames'])->toBe([$oldMask])
    ->and((new TileCoverage($tileset, $this->root))->readFrames(1)['frames'])->toBe([$newMask])
    ->and(file_get_contents($path))->toBe($newBytes);
});

it('reports unavailable layers rather than guessing coverage even above a complete substrate', function (string $failure) {
  $solid = array_fill(0, 16, true);
  writeTileCoveragePng($this->root . '/B.png', TilesetSheet::B, 4,
    static fn(GdImage $image) => paintTileCoverageMask($image, 4, 0, 4, $solid));
  $sheets = ['B' => 'B.png', 'C' => 'C.png'];
  $root = $this->root;
  $path = $this->root . '/C.png';
  switch ($failure) {
    case 'missing file': break;
    case 'missing sheet': unset($sheets['C']); break;
    case 'corrupt PNG': file_put_contents($path, 'not a PNG'); break;
    case 'wrong layout':
      writeTileCoveragePng($path, TilesetSheet::A5, 4, static function (GdImage $image): void {});
      break;
    case 'different tile size':
      writeTileCoveragePng($path, TilesetSheet::C, 8, static function (GdImage $image): void {});
      break;
    case 'missing root': $root .= '/missing'; break;
    case 'root escape':
      $outside = $this->fixtureRoot . '/outside.png';
      writeTileCoveragePng($outside, TilesetSheet::C, 4,
        static fn(GdImage $image) => paintTileCoverageMask($image, 0, 0, 4, $solid));
      expect(symlink($outside, $path))->toBeTrue();
      break;
  }
  $before = is_file($path) ? file_get_contents($path) : null;
  $reader = new TileCoverage(new Tileset('unavailable', 'Unavailable', $sheets), $root);
  $result = $reader->readFrames(TileId::C);
  expect($result['frames'])->toBeNull()->and($result['diagnostic'])->toBeString()->not->toBeEmpty();
  foreach ([[TileId::C], [1, TileId::C], [TileId::C, 1]] as $stack) {
    $result = $reader->findUncovered($stack);
    expect($result['uncoveredPixels'])->toBeNull()->and($result['diagnostic'])->toBeString()->not->toBeEmpty();
  }
  if ($failure !== 'missing root') {
    expect($reader->findUncovered([1]))->toBe(['tileSize' => 4, 'uncoveredPixels' => 0, 'diagnostic' => null]);
  }
  if ($before !== null) { expect(file_get_contents($path))->toBe($before); }
})->with(['missing file', 'missing sheet', 'corrupt PNG', 'wrong layout', 'different tile size', 'missing root', 'root escape']);

it('reports a sheet that passes header preflight but cannot be decoded without guessing a covered stack', function () {
  writeTileCoveragePng($this->root . '/B.png', TilesetSheet::B, 4,
    static fn(GdImage $image) => paintTileCoverageMask($image, 4, 0, 4, array_fill(0, 16, true)));
  $path = $this->root . '/C.png';
  $bytes = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', 64, 64);
  file_put_contents($path, $bytes);
  // GD's expected corrupt-image warning belongs in the child, not PHPUnit's error handler.
  $code = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; '
    . '\\Ichiloto\\Engine\\Util\\Debug::configure(["log_directory" => '
    . var_export($this->fixtureRoot . '/logs', true) . ']); '
    . '$tileset = new \\Ichiloto\\Engine\\Rendering\\Tilesets\\Tileset("corrupt", "Corrupt", ["B" => "B.png", "C" => "C.png"]); '
    . '$reader = new \\Ichiloto\\Engine\\Rendering\\Tilesets\\TileCoverage($tileset, '
    . var_export($this->root, true) . '); '
    . 'echo json_encode(["frames" => $reader->readFrames(256), "stack" => $reader->findUncovered([1, 256]), '
    . '"reversed" => $reader->findUncovered([256, 1]), "substrate" => $reader->findUncovered([1])], JSON_THROW_ON_ERROR);';
  exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code), $output, $exit);
  expect($exit)->toBe(0);
  $result = json_decode(implode('', $output), true, flags: JSON_THROW_ON_ERROR);
  expect($result['frames']['frames'])->toBeNull()->and($result['frames']['diagnostic'])->toContain('decode');
  foreach (['stack', 'reversed'] as $key) {
    expect($result[$key]['uncoveredPixels'])->toBeNull()->and($result[$key]['diagnostic'])->toContain('decode');
  }
  expect($result['substrate'])->toBe(['tileSize' => 4, 'uncoveredPixels' => 0, 'diagnostic' => null])
    ->and(file_get_contents($path))->toBe($bytes);
});

it('rejects invalid integer identities before reporting unavailable inspection', function (int $id) {
  $reader = new TileCoverage(new Tileset('missing', 'Missing', ['B' => 'missing.png']), $this->root);
  expect(fn() => $reader->readFrames($id))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $reader->findUncovered([$id]))->toThrow(InvalidArgumentException::class);
})->with([-1, TileId::A5 + 128, 1700, TileId::A1 - 1, TileId::MAX, PHP_INT_MAX]);

it('requires a strict list of valid integer identities even when inspection is unavailable', function (array $stack) {
  $reader = new TileCoverage(new Tileset('missing', 'Missing', ['B' => 'missing.png']), $this->root);
  expect(fn() => $reader->findUncovered($stack))->toThrow(InvalidArgumentException::class);
})->with([
  'nonzero first key' => [[1 => 1]],
  'sparse list' => [[0 => 1, 2 => 2]],
  'named key' => [['tile' => 1]],
  'numeric string' => [['1']],
  'float' => [[1.0]],
  'boolean' => [[true]],
  'null' => [[null]],
  'nested list' => [[[1]]],
  'valid then invalid' => [[1, TileId::MAX]],
]);

it('reports absent optional GD in a child process without changing runtime sheet preflight', function () {
  // This case only preflights a header: the child must never attempt PNG decoding.
  file_put_contents($this->root . '/B.png', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', 64, 64));
  $code = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; '
    . '$root = ' . var_export($this->root, true) . '; '
    . '$tileset = new \\Ichiloto\\Engine\\Rendering\\Tilesets\\Tileset("optional", "Optional", ["B" => "B.png"]); '
    . '$reader = new \\Ichiloto\\Engine\\Rendering\\Tilesets\\TileCoverage($tileset, $root); '
    . 'echo json_encode(["hasDecoder" => function_exists("imagecreatefrompng"), '
    . '"usable" => $tileset->getUsableSheets($root), "frames" => $reader->readFrames(1), '
    . '"emptyFrames" => $reader->readFrames(0), "stack" => $reader->findUncovered([1]), '
    . '"emptyStack" => $reader->findUncovered([])], JSON_THROW_ON_ERROR);';
  $command = escapeshellarg(PHP_BINARY) . ' -n -d disable_functions=imagecreatefrompng -r ' . escapeshellarg($code);
  exec($command, $output, $exit);
  expect($exit)->toBe(0);
  $result = json_decode(implode('', $output), true, flags: JSON_THROW_ON_ERROR);
  expect($result['hasDecoder'])->toBeFalse()
    ->and($result['usable'])->toBe(['tileSize' => 4, 'sheets' => ['B' => 'B.png']]);
  foreach (['frames', 'emptyFrames', 'stack', 'emptyStack'] as $key) {
    $entry = $result[$key];
    expect($entry['tileSize'])->toBeNull()->and($entry['diagnostic'])->toContain('GD')
      ->and($entry[$key === 'frames' || $key === 'emptyFrames' ? 'frames' : 'uncoveredPixels'])->toBeNull();
  }
});

it('retains numeric tile identities and correct masks after bounded frame-cache eviction', function () {
  $sheets = [];
  $expected = [];
  foreach ([TilesetSheet::B, TilesetSheet::C, TilesetSheet::D] as $sheetIndex => $sheet) {
    $start = $sheetIndex * 256;
    $sheets[$sheet->value] = $sheet->value . '.png';
    writeTileCoveragePng($this->root . '/' . $sheets[$sheet->value], $sheet, 4,
      static function (GdImage $image) use ($start, &$expected): void {
        for ($id = max(1, $start); $id < $start + 256; $id++) {
          $mask = createTileCoverageMask(4, static fn(int $x, int $y): bool => (($id >> ($y * 4 + $x)) & 1) === 1);
          $expected[$id] = $mask;
          $piece = TileComposer::compose($id, 4)[0][0];
          paintTileCoverageMask($image, $piece->x, $piece->y, 4, $mask);
        }
      });
  }
  $reader = new TileCoverage(new Tileset('cache', 'Cache', $sheets), $this->root);
  foreach (range(1, 520) as $id) {
    expect($reader->readFrames($id))->toBe(['tileSize' => 4, 'frames' => [$expected[$id]], 'diagnostic' => null]);
  }
  foreach ([2, 256, 511, 512, 1, 513, 520] as $id) {
    expect($reader->readFrames($id)['frames'])->toBe([$expected[$id]]);
  }
  $reference = countTileCoverageCartesianExposure([[$expected[2]], [$expected[256]]], 4);
  expect($reader->findUncovered([2, 256]))->toBe(['tileSize' => 4, 'uncoveredPixels' => $reference['uncoveredPixels'], 'diagnostic' => null]);
});
