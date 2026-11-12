<?php

declare(strict_types=1);

/**
 * Writes a placeholder corner sheet in the corner sheet layout
 * ({@see \Ichiloto\Engine\Rendering\Tilesets\CornerShape::LAYOUT}): animated
 * water with a sand shore, or still sand, for proving corner-edged terrain
 * before an artist draws the real sheet, and as the layout template the Art
 * spec shows (`--labels`).
 *
 *   php tools/corner-sheet-placeholder.php [--material=water|sand] [--tile=48] [--labels] <out.png>
 *
 * Every tile is drawn from one field, the depth inside the kind's edge,
 * that depends on a tile side only through the two cells that side joins:
 * each edge crosses a tile side at its midpoint, at a right angle, so any
 * two tiles that meet in a map continue each other. Corners are flattened
 * quarter superellipses (exponent 1.35): a stair-stepped run of cells reads
 * as a nearly straight diagonal, and a one-cell diagonal stream stays an
 * even ribbon where its cells touch. Round quarter circles (exponent 2)
 * would scallop every diagonal instead.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Ichiloto\Engine\Rendering\Tilesets\CornerSheet;
use Ichiloto\Engine\Rendering\Tilesets\CornerShape;
use Ichiloto\Engine\Rendering\Tilesets\TileComposer;

$options = getopt('', ['material:', 'tile:', 'labels'], $rest);
$output = $argv[$rest] ?? null;
$material = $options['material'] ?? 'water';
$tile = (int)($options['tile'] ?? 48);
if ($output === null || !in_array($material, ['water', 'sand'], true) || $tile < 8 || $tile % 2 !== 0
  || !function_exists('imagecreatetruecolor')) {
  fwrite(STDERR, "Usage: php tools/corner-sheet-placeholder.php [--material=water|sand] [--tile=48 (even, 8 or more)] [--labels] <out.png>\nNeeds PHP GD.\n");
  exit(1);
}
$phases = $material === 'water' ? 3 : 1;

/** The superellipse distance from a tile corner, in tiles: exponent 2 is round, nearer 1 is flatter. */
$distance = static fn(float $u, float $v, array $corner, float $exponent): float
  => (abs($u - $corner[0]) ** $exponent + abs($v - $corner[1]) ** $exponent) ** (1 / $exponent);

/** Depth inside the kind's edge, in tiles, up to half a tile; negative outside. */
$depth = static function (int $case, float $u, float $v) use ($distance): float {
  $lobe = 1.35;
  $corners = [[0, 0], [1, 0], [0, 1], [1, 1]];
  $in = array_values(array_filter([0, 1, 2, 3], static fn(int $bit): bool => ($case >> $bit & 1) === 1));
  $out = array_values(array_diff([0, 1, 2, 3], $in));
  $value = match (count($in)) {
    4 => 0.5,
    1 => 0.5 - $distance($u, $v, $corners[$in[0]], $lobe),
    3 => $distance($u, $v, $corners[$out[0]], $lobe) - 0.5,
    2 => match ($case) {
      CornerShape::TOP_LEFT | CornerShape::TOP_RIGHT => 0.5 - $v,
      CornerShape::BOTTOM_LEFT | CornerShape::BOTTOM_RIGHT => $v - 0.5,
      CornerShape::TOP_LEFT | CornerShape::BOTTOM_LEFT => 0.5 - $u,
      CornerShape::TOP_RIGHT | CornerShape::BOTTOM_RIGHT => $u - 0.5,
      // Diagonal: the kind joins through the corner, between two outside lobes.
      default => min($distance($u, $v, $corners[$out[0]], $lobe), $distance($u, $v, $corners[$out[1]], $lobe)) - 0.5,
    },
    default => -1.0,
  };
  return min(0.5, $value);
};

$mix = static fn(array $a, array $b, float $t): array => array_map(static fn(float $x, float $y): float => $x + ($y - $x) * $t, $a, $b);
$smooth = static function (float $edge0, float $edge1, float $x): float {
  $t = max(0.0, min(1.0, ($x - $edge0) / ($edge1 - $edge0)));
  return $t * $t * (3 - 2 * $t);
};
/** Patterns repeat a whole number of times per tile, so tiles that meet carry them across. */
$wave = static fn(float $u, float $v, float $shift): float => sin(2 * M_PI * ($u + $v + $shift)) * 0.5
  + sin(2 * M_PI * (2 * $u - $v - $shift)) * 0.35 + sin(2 * M_PI * ($u - 2 * $v + 2 * $shift)) * 0.25;
/** Short wavy highlight dashes drifting across the water. */
$ripple = static fn(float $u, float $v, float $shift): float
  => max(0.0, sin(2 * M_PI * (3 * $v + 0.18 * sin(2 * M_PI * (2 * $u + $shift))))) ** 8
  * max(0.0, sin(2 * M_PI * ($u + 2 * $v - $shift))) ** 2;

/** One sample's colour and opacity (0 to 1) at depth $d and tile position ($u, $v) in phase $phase. */
$shade = match ($material) {
  'water' => static function (float $d, float $u, float $v, int $phase) use ($mix, $smooth, $ripple): array {
    $band = 0.13;
    $ring = 0.07;
    if ($d < -$ring) {
      return [[0, 0, 0], 0.0];
    }
    if ($d < 0) {
      // Damp ground just outside the shore.
      return [[40, 52, 22], 0.3 * (1 + $d / $ring)];
    }
    if ($d < $band) {
      // Dry sand outside, wet sand at the water.
      return [$mix([236, 214, 152], [190, 160, 100], $smooth(0.0, $band, $d)), 1.0];
    }
    $deep = $smooth($band, 0.5, $d);
    $shift = $phase / 3;
    $colour = $mix([104, 184, 222], [34, 88, 164], $deep);
    $colour = $mix($colour, [196, 232, 250], 0.75 * $ripple($u, $v, $shift));
    // A thin foam line where the water meets the sand, breathing with the phase.
    $foam = 1 - $smooth(0.0, 0.04, $d - $band);
    return [$mix($colour, [240, 248, 250], $foam * (0.6 + 0.2 * sin(2 * M_PI * $shift))), 1.0];
  },
  default => static function (float $d, float $u, float $v) use ($mix, $smooth, $wave): array {
    $ring = 0.05;
    if ($d < -$ring) {
      return [[0, 0, 0], 0.0];
    }
    if ($d < 0) {
      return [[60, 50, 20], 0.22 * (1 + $d / $ring)];
    }
    $colour = $mix([214, 186, 120], [232, 208, 146], $smooth(0.0, 0.18, $d));
    return [$mix($colour, [246, 228, 176], max(0.0, $wave(2 * $u, 2 * $v, 0.0) - 0.7) * 0.6), 1.0];
  },
};

$samples = 4;
$width = $phases * CornerSheet::COLUMNS * $tile;
$height = CornerSheet::ROWS * $tile;
$image = imagecreatetruecolor($width, $height);
imagealphablending($image, false);
imagesavealpha($image, true);
imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
for ($phase = 0; $phase < $phases; $phase++) {
  foreach (CornerShape::LAYOUT as $case => [$column, $row]) {
    // Case 0 holds none of the kind and is never drawn; it stays empty.
    if ($case === 0) {
      continue;
    }
    for ($py = 0; $py < $tile; $py++) {
      for ($px = 0; $px < $tile; $px++) {
        $rgb = [0.0, 0.0, 0.0];
        $alpha = 0.0;
        for ($sy = 0; $sy < $samples; $sy++) {
          for ($sx = 0; $sx < $samples; $sx++) {
            $u = ($px + ($sx + 0.5) / $samples) / $tile;
            $v = ($py + ($sy + 0.5) / $samples) / $tile;
            [$colour, $opacity] = $shade($depth($case, $u, $v), $u, $v, $phase);
            foreach ($colour as $channel => $value) {
              $rgb[$channel] += $value * $opacity;
            }
            $alpha += $opacity;
          }
        }
        if ($alpha <= 0) {
          continue;
        }
        $colour = array_map(static fn(float $value): int => (int)round(min(255, $value / $alpha)), $rgb);
        imagesetpixel($image, ($phase * CornerSheet::COLUMNS + $column) * $tile + $px, $row * $tile + $py,
          imagecolorallocatealpha($image, $colour[0], $colour[1], $colour[2], (int)round(127 * (1 - $alpha / $samples ** 2))));
      }
    }
  }
}
if (isset($options['labels'])) {
  imagealphablending($image, true);
  $ink = imagecolorallocate($image, 20, 20, 20);
  $paper = imagecolorallocatealpha($image, 255, 255, 255, 30);
  $grid = imagecolorallocatealpha($image, 0, 0, 0, 90);
  for ($phase = 0; $phase < $phases; $phase++) {
    foreach (CornerShape::LAYOUT as $case => [$column, $row]) {
      $x = ($phase * CornerSheet::COLUMNS + $column) * $tile;
      $y = $row * $tile;
      imagerectangle($image, $x, $y, $x + $tile - 1, $y + $tile - 1, $grid);
      imagefilledrectangle($image, $x + 1, $y + 1, $x + 12, $y + 10, $paper);
      imagestring($image, 1, $x + 2, $y + 2, (string)$case, $ink);
    }
  }
}
if (!imagepng($image, $output)) {
  fwrite(STDERR, "Could not write {$output}.\n");
  exit(1);
}
echo "Wrote {$output}: {$width}x{$height}, {$material}, " . ($phases === 1 ? 'still' : 'phases cycled ' . implode(', ', TileComposer::WATER_FRAMES)) . ".\n";
