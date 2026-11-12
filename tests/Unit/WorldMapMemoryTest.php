<?php

declare(strict_types=1);

use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Rendering\Tilesets\TilesetSheet;

/**
 * A synthetic classic world map: 320 x 256 cells painted in terrain kinds
 * (grass drawn as a pictograph, sand and corner-edged water), with buildings, trees and a
 * decoration layer over them, and twelve tile layers, ten of them dense with
 * varied plain tiles. Its shapes come from a fixed hash, never from a real
 * game's art or layout.
 */
final class WorldMapMemoryFixture
{
    public const int COLUMNS = 320;
    public const int ROWS = 256;
    public const int SIZE = 8;
    public const int GRASS = 2816;
    public const int SAND = 2864;
    public const int WATER = 2096;

    public static function write(string $root): void
    {
        $assets = $root . '/assets';
        foreach (['A1', 'A2', 'B'] as $sheet) {
            $layout = TilesetSheet::from($sheet);
            self::writePng("{$assets}/Graphics/{$sheet}.png", $layout->getColumns() * self::SIZE, $layout->getRows() * self::SIZE,
                static fn(int $x, int $y): array => [($x * 7) % 256, ($y * 5) % 256, 120, 255]);
        }
        self::writePng("{$assets}/Graphics/Water.corners.png", 3 * 4 * self::SIZE, 4 * self::SIZE,
            static fn(int $x, int $y): array => [40, 90, 200, ($x + $y) % 2 === 0 ? 255 : 0]);
        self::writePhp("{$assets}/Data/Tilesets/synthetic-world.php", [
            'name' => 'Synthetic World',
            'sheets' => ['A1' => 'Graphics/A1.png', 'A2' => 'Graphics/A2.png', 'B' => 'Graphics/B.png'],
            'kinds' => [
                'grass' => ['name' => 'Grass', 'layer' => 'terrain', 'glyph' => '<fg=green>♣</>',
                    'art' => ['layer' => 'ground', 'tile' => self::GRASS], 'passage' => 'walk', 'encounters' => 'grassland', 'atlas' => 'ground'],
                'sand' => ['name' => 'Sand', 'layer' => 'terrain', 'glyph' => '<fg=yellow>:</>',
                    'art' => ['layer' => 'ground', 'tile' => self::SAND], 'passage' => 'walk', 'encounters' => 'desert', 'atlas' => 'ground'],
                'water' => ['name' => 'Water', 'layer' => 'terrain', 'glyph' => '<fg=blue>~</>',
                    'art' => ['layer' => 'water', 'tile' => self::WATER, 'corners' => 'Graphics/Water.corners.png'],
                    'passage' => 'solid', 'atlas' => 'water', 'keeps' => ['ground']],
            ],
            'shadows' => ['casters' => [1, 2, 3], 'width' => 0.5, 'opacity' => 0.35],
        ]);
        self::writeFile("{$assets}/Maps/collisions.php", "<?php\n\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n\n"
            . "return ['#' => CollisionType::SOLID, 'T' => CollisionType::SOLID, '*' => CollisionType::NONE, ' ' => CollisionType::NONE];\n");

        $world = "{$assets}/Maps/synthetic/world";
        $terrain = $buildings = $trees = $clouds = $events = [];
        $tiles = array_fill(0, 12, []);
        for ($y = 0; $y < self::ROWS; $y++) {
            $glyphs = $walls = $fixtures = $sky = $marks = '';
            $row = array_fill(0, 12, []);
            for ($x = 0; $x < self::COLUMNS; $x++) {
                $shape = sin($x / 23) + cos($y / 17) + sin(($x + $y) / 31);
                $noise = self::hash($x, $y);
                $kind = $shape < -0.9 ? 'water' : ($shape < -0.6 ? 'sand' : 'grass');
                $glyphs .= ['water' => '<fg=blue>~</>', 'sand' => '<fg=yellow>:</>', 'grass' => '<fg=green>♣</>'][$kind];
                $wall = $kind !== 'water' && $x % 40 >= 10 && $x % 40 <= 14 && $y % 32 >= 8 && $y % 32 <= 12
                    && ($x % 40 === 10 || $x % 40 === 14 || $y % 32 === 8 || $y % 32 === 12);
                $walls .= $wall ? '<fg=white>#</>' : ' ';
                $fixtures .= !$wall && $kind === 'grass' && $noise % 9 === 0 ? '<fg=green>T</>' : ' ';
                $sky .= $noise % 50 === 0 ? '<fg=white>*</>' : ' ';
                $marks .= $x === 5 && $y === 5 ? 'A' : ' ';
                $row[0][] = $kind === 'sand' ? self::SAND : self::GRASS;
                $row[1][] = $kind === 'water' ? self::WATER : 0;
                for ($layer = 2; $layer < 12; $layer++) {
                    $value = ($noise >> $layer) % 255;
                    $row[$layer][] = $value % 3 === 0 ? 0 : 1 + $value;
                }
            }
            $terrain[] = $glyphs;
            $buildings[] = $walls;
            $trees[] = $fixtures;
            $clouds[] = $sky;
            $events[] = $marks;
            foreach ($row as $layer => $cells) { $tiles[$layer][] = implode(' ', $cells); }
        }
        self::writeGrid("{$world}/layers/00.terrain.map.php", $terrain);
        self::writeGrid("{$world}/layers/01.buildings.map.php", $buildings);
        self::writeGrid("{$world}/layers/02.fixtures.map.php", $trees);
        self::writeGrid("{$world}/layers/03.clouds.deco.php", $clouds);
        self::writeGrid("{$world}/world.event.php", $events);
        foreach ($tiles as $layer => $rows) {
            $name = ['ground', 'water'][$layer] ?? 'detail-' . $layer;
            self::writeGrid(sprintf('%s/graphics/%02d.%s.tiles.php', $world, $layer, $name), $rows);
        }
        self::writePhp("{$world}/world.data.php", ['name' => 'Synthetic World', 'tileset' => 'synthetic-world', 'terrainKinds' => true,
            'events' => ['A' => ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\TransferPlayerTrigger',
                'data' => ['destinationMap' => 'synthetic/hamlet', 'spawnPoint' => ['x' => 1, 'y' => 1], 'spawnSprite' => ['South']]]]]);

        $hamlet = "{$assets}/Maps/synthetic/hamlet";
        self::writeGrid("{$hamlet}/layers/00.terrain.map.php", array_fill(0, 6, str_repeat(' ', 8)));
        self::writeGrid("{$hamlet}/hamlet.event.php", array_fill(0, 6, str_repeat(' ', 8)));
        self::writePhp("{$hamlet}/hamlet.data.php", ['name' => 'Synthetic Hamlet', 'events' => []]);
    }

    /** A fixed, well-mixed value per cell. */
    private static function hash(int $x, int $y): int
    {
        return (($x * 73856093) ^ ($y * 19349663) ^ ($x * $y * 83492791)) & 0x7FFFFFFF;
    }

    /** @param list<string> $rows */
    private static function writeGrid(string $path, array $rows): void
    {
        self::writeFile($path, MapGridSource::buildSource(implode("\n", $rows), 'SYNTHETIC'));
    }

    private static function writePhp(string $path, array $data): void
    {
        self::writeFile($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    }

    private static function writeFile(string $path, string $contents): void
    {
        is_dir(dirname($path)) || mkdir(dirname($path), 0700, true);
        file_put_contents($path, $contents) !== false ?: throw new RuntimeException("Cannot write {$path}.");
    }

    /** A whole-pixel PNG writer, so the fixture needs no GD. */
    private static function writePng(string $path, int $width, int $height, callable $pixel): void
    {
        $bytes = '';
        for ($y = 0; $y < $height; $y++) {
            $bytes .= "\0";
            for ($x = 0; $x < $width; $x++) { $bytes .= pack('C4', ...$pixel($x, $y)); }
        }
        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        self::writeFile($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            . $chunk('IDAT', gzcompress($bytes)) . $chunk('IEND', ''));
    }
}

it('loads, presents and leaves a classic world map within the default memory limit and in bounded time, releasing it on every transfer', function () {
    $root = createTestDirectory('ichiloto-world-memory-');
    WorldMapMemoryFixture::write($root);
    mkdir($root . '/logs');
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'max_execution_time=60',
        __DIR__ . '/../Support/WorldMapMemoryProbe.php', $root, $root . '/logs'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/stdout', 'w'], 2 => ['file', $root . '/stderr', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    $exitCode = proc_close($process);
    $this->assertSame(0, $exitCode, file_get_contents($root . '/stderr') . file_get_contents($root . '/stdout'));
    $result = json_decode(file_get_contents($root . '/stdout'), true, flags: JSON_THROW_ON_ERROR);
    $megabytes = 1024 * 1024;
    expect($result['memoryLimit'])->toBe('128M')
        ->and($result['world']['columns'])->toBe(WorldMapMemoryFixture::COLUMNS)
        ->and($result['world']['rows'])->toBe(WorldMapMemoryFixture::ROWS)
        ->and($result['world']['packets'])->toBeGreaterThan(1)
        // Preparing, installing, presenting and sending the world stays a quarter of the default limit,
        // leaving the rest of the game its room ...
        ->and($result['worldPeakBytes'])->toBeLessThan(32 * $megabytes)
        // ... and the installed world, terminal and graphical, is held compactly.
        ->and($result['worldRetainedBytes'])->toBeLessThan(24 * $megabytes)
        // Leaving the world releases it: what remains is the code its first visit loaded,
        // and coming and going again adds nothing.
        ->and($result['afterLeavingBytes'])->toBeLessThan(2 * $megabytes)
        ->and($result['afterReturningBytes'] - $result['afterLeavingBytes'])->toBeLessThan(256 * 1024)
        // The transfer builds the world once and every later frame reads it.
        ->and($result['world']['reused'])->toBeTrue()
        // A generous bound on preparing, installing, presenting and sending the whole world: it takes
        // about a second and a half. Work that grows with a row's length per cell or per tag, as
        // formatting each styled row whole did, took this world over twenty seconds.
        ->and($result['world']['milliseconds'])->toBeLessThan(8000);
});
