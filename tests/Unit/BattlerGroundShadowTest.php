<?php

use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattlerContact;
use Ichiloto\Engine\Battle\Presentation\BattlerGroundContact;
use Ichiloto\Engine\Battle\Presentation\BattlerShadowStyle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Sprites\PngAssetBounds;

/**
 * A battler's shadow lies where its drawn art meets the ground, read from the current image in the pose drawn.
 * Synthetic art only: opaque rectangles for bodies, legs, feet, hems and tails on a transparent frame.
 */

/** Writes a 200 by 200 transparent PNG with opaque rectangles [x, y, width, height]. */
function writeGroundShadowArt(string $path, array $rectangles): void
{
  if (!is_dir(dirname($path))) { mkdir(dirname($path), 0777, true); }
  $image = imagecreatetruecolor(200, 200);
  imagesavealpha($image, true);
  imagealphablending($image, false);
  imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
  foreach ($rectangles as [$x, $y, $width, $height]) {
    imagefilledrectangle($image, $x, $y, $x + $width - 1, $y + $height - 1, imagecolorallocatealpha($image, 90, 60, 40, 0));
  }
  imagepng($image, $path);
}

/** The shadow of art drawn at a destination, as the battle draws a grounded battler's. */
function composeGroundShadow(string $root, string $asset, CanvasRectangle $destination, bool $flipX = false,
  float $elevation = 0, float $core = .5): CanvasComposite
{
  $layout = new BattleCanvasLayout(1350, 720, shadow: new BattlerShadowStyle(PresentationColor::rgb(10, 10, 20), opacity: .5,
    flatness: .28, contactCore: $core));
  $arena = new BattleArenaDefinition('Field', new CanvasImage('field', 'field.png', new CanvasRectangle(0, 0, 1350, 720)));
  return BattlerGroundContact::compose('shadow', $destination->x + $destination->width / 2, $destination->y + $destination->height,
    90, false, $destination->width, $elevation, BattlerContact::SHADOW, $layout, $arena, null, $root,
    drawn: new CanvasImage('art', $asset, $destination, 100, flipX: $flipX))['composites'][0];
}

/** @return array{left: float, right: float, top: float, bottom: float} The shadow's outline in canvas units. */
function getGroundShadowExtent(CanvasComposite $shadow): array
{
  $brush = $shadow->operations[0]->data['brush'];
  [$cx, $cy] = $brush['center']; [$rx, $ry] = $brush['radius'];
  return ['left' => $shadow->destination->x + $cx - $rx, 'right' => $shadow->destination->x + $cx + $rx,
    'top' => $shadow->destination->y + $cy - $ry, 'bottom' => $shadow->destination->y + $cy + $ry];
}

beforeEach(function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Ground contact is read with optional GD PNG support.'); }
  $this->root = createTestDirectory('ground-shadow');
});

it('shades a long low body from its back feet and belly to its front feet, not under a raised tail', function () {
  // Seen in three-quarter view: the body 50..170 across, its back feet higher on screen (further back) than its
  // front feet, its belly low between them, and a tail raised up and away to the left.
  writeGroundShadowArt($this->root . '/Lizard.png', [[50, 100, 120, 40], [60, 140, 14, 26], [150, 140, 14, 46],
    [90, 140, 40, 16], [10, 60, 40, 12]]);
  $extent = getGroundShadowExtent(composeGroundShadow($this->root, 'Lizard.png', new CanvasRectangle(400, 300, 200, 200)));

  // Back foot 60..74 standing at 166, front foot 150..164 at 186 (canvas: x 460..474 at 466, x 550..564 at 486).
  expect($extent['left'])->toBeLessThan(460.0)->and($extent['right'])->toBeGreaterThan(564.0)
    ->and($extent['top'])->toBeLessThan(466.0)->and($extent['bottom'])->toBeGreaterThan(486.0)
    // The tail held up at 10..50 casts nothing on the ground here.
    ->and($extent['left'])->toBeGreaterThan(430.0);
});

it('grounds both feet of a wide staggered stance and leaves a hem hanging above them out', function () {
  // A body from 20 to 140, a front leg to 190 and a back leg to 170, and a coat hem tip hanging to 158 at the far left.
  writeGroundShadowArt($this->root . '/Stance.png', [[60, 20, 80, 120], [60, 140, 20, 50], [120, 140, 20, 30], [40, 120, 4, 38]]);
  $contact = PngAssetBounds::getGroundContact($this->root, new CanvasImage('art', 'Stance.png', new CanvasRectangle(0, 0, 200, 200)), .28);
  $extent = getGroundShadowExtent(composeGroundShadow($this->root, 'Stance.png', new CanvasRectangle(400, 300, 100, 100)));

  expect($contact['top'])->toBe(.1)->and($contact['bottom'])->toBe(.95)
    ->and(min(array_column($contact['patches'], 0)))->toBe(.3)->and(max(array_column($contact['patches'], 1)))->toBe(.7)
    ->and(array_column($contact['patches'], 2))->toContain(.95, .85)
    // Drawn at half size from 400, 300: the front foot 430..440 at 395, the back foot 460..470 at 385.
    ->and($extent['left'])->toBeLessThan(430.0)->and($extent['right'])->toBeGreaterThan(470.0)
    ->and($extent['top'])->toBeLessThan(385.0)->and($extent['bottom'])->toBeGreaterThan(395.0)
    ->and($extent['left'])->toBeGreaterThan(420.0);
});

it('keeps a narrow figure\'s shadow narrow and under its feet', function () {
  writeGroundShadowArt($this->root . '/Narrow.png', [[90, 10, 20, 150], [94, 160, 12, 30]]);
  $extent = getGroundShadowExtent(composeGroundShadow($this->root, 'Narrow.png', new CanvasRectangle(400, 300, 200, 200)));

  expect(($extent['left'] + $extent['right']) / 2)->toEqualWithDelta(500.0, 1.0)
    ->and($extent['right'] - $extent['left'])->toBeLessThan(50.0)
    ->and($extent['bottom'])->toBeGreaterThan(490.0);
});

it('mirrors with the art and follows it as it moves', function () {
  writeGroundShadowArt($this->root . '/Lizard.png', [[50, 100, 120, 40], [60, 140, 14, 26], [150, 140, 14, 46], [90, 140, 40, 16]]);
  $rest = getGroundShadowExtent(composeGroundShadow($this->root, 'Lizard.png', new CanvasRectangle(400, 300, 200, 200)));
  $mirrored = getGroundShadowExtent(composeGroundShadow($this->root, 'Lizard.png', new CanvasRectangle(400, 300, 200, 200), true));
  $stepped = getGroundShadowExtent(composeGroundShadow($this->root, 'Lizard.png', new CanvasRectangle(437, 288, 200, 200)));

  expect($mirrored['left'])->toEqualWithDelta(1000 - $rest['right'], 1.0)
    ->and($mirrored['right'])->toEqualWithDelta(1000 - $rest['left'], 1.0)
    ->and($stepped['left'] - $rest['left'])->toEqualWithDelta(37.0, 1.0)
    ->and($stepped['bottom'] - $rest['bottom'])->toEqualWithDelta(-12.0, 1.0);
});

it('lies along a lying body\'s whole lower edge', function () {
  // A knockout pose: a body lying across the frame, its lower edge stepping down toward the right.
  writeGroundShadowArt($this->root . '/Lying.png', [[20, 100, 40, 30], [60, 100, 40, 40], [100, 100, 60, 50]]);
  $extent = getGroundShadowExtent(composeGroundShadow($this->root, 'Lying.png', new CanvasRectangle(0, 0, 200, 200)));

  expect($extent['left'])->toBeLessThan(20.0)->and($extent['right'])->toBeGreaterThan(160.0);
});

it('reads replaced art afresh, and keeps the feet-point ellipse in the air or without readable art', function () {
  writeGroundShadowArt($this->root . '/Art.png', [[90, 20, 20, 160]]);
  $image = new CanvasImage('art', 'Art.png', new CanvasRectangle(0, 0, 200, 200));
  $first = PngAssetBounds::getGroundContact($this->root, $image, .28)['patches'];
  // The same file replaced in place by a wider stance: the content, not its name or timestamps, decides.
  writeGroundShadowArt($this->root . '/Art.png', [[60, 20, 80, 120], [40, 140, 20, 40], [140, 140, 20, 40]]);
  $second = PngAssetBounds::getGroundContact($this->root, $image, .28)['patches'];
  file_put_contents($this->root . '/Broken.png', 'not a png');

  expect(min(array_column($first, 0)))->toBe(.45)
    ->and(min(array_column($second, 0)))->toBe(.2)->and(max(array_column($second, 1)))->toBe(.8)
    ->and(PngAssetBounds::getGroundContact($this->root, new CanvasImage('art', 'Broken.png', new CanvasRectangle(0, 0, 9, 9)), .28))->toBeNull()
    ->and(composeGroundShadow($this->root, 'Art.png', new CanvasRectangle(400, 300, 100, 100), elevation: .4)
      ->operations[0]->data['brush']['type'])->toBe('radial')
    ->and(composeGroundShadow($this->root, 'Broken.png', new CanvasRectangle(400, 300, 100, 100))
      ->operations[0]->data['brush']['type'])->toBe('radial');
});


/** Samples the generic source-over radial descriptor contract in canvas coordinates. */
function sampleGroundShadowAlpha(CanvasComposite $shadow, float $worldX, float $worldY): float
{
  $x = $worldX - $shadow->destination->x; $y = $worldY - $shadow->destination->y;
  if ($x < 0 || $y < 0 || $x >= $shadow->width || $y >= $shadow->height) { return 0; }
  $alpha = 0.0;
  foreach ($shadow->operations as $operation) {
    $data = $operation->data; $brush = $data['brush'];
    expect($brush['type'])->toBe('radial');
    $distance = hypot(($x - $brush['center'][0]) / $brush['radius'][0], ($y - $brush['center'][1]) / $brush['radius'][1]);
    $stops = $brush['stops']; $value = $stops[0]['opacity'];
    foreach ($stops as $index => $stop) {
      if ($distance >= $stop['offset']) { $value = $stop['opacity']; continue; }
      if ($index > 0) {
        $previous = $stops[$index - 1]; $share = ($distance - $previous['offset']) / ($stop['offset'] - $previous['offset']);
        $value = $previous['opacity'] * (1 - $share) + $stop['opacity'] * $share;
      }
      break;
    }
    $value *= $data['opacity']; $alpha = $value + $alpha * (1 - $value);
  }
  return $alpha * $shadow->opacity;
}

it('fades continuously to an ellipse rim at small, ordinary and large battler scales', function (int $size) {
  writeGroundShadowArt($this->root . '/Contact.png', [[40, 80, 120, 50], [50, 130, 20, 35], [140, 130, 20, 60]]);
  $shadow = composeGroundShadow($this->root, 'Contact.png', new CanvasRectangle(300, 50, $size, $size), core: 0);
  $brush = $shadow->operations[0]->data['brush'];
  [$x, $y] = [$shadow->destination->x + $brush['center'][0], $shadow->destination->y + $brush['center'][1]];
  [$rx, $ry] = $brush['radius'];
  $inner = sampleGroundShadowAlpha($shadow, $x + .45 * $rx, $y);
  $middle = sampleGroundShadowAlpha($shadow, $x + .7 * $rx, $y);
  $rim = sampleGroundShadowAlpha($shadow, $x + .95 * $rx, $y);
  expect($inner)->toBeGreaterThan($middle)->and($middle)->toBeGreaterThan($rim)->and($rim)->toBeGreaterThan(0)
    ->and(sampleGroundShadowAlpha($shadow, $x + $rx, $y))->toEqualWithDelta(0, .00001)
    ->and(sampleGroundShadowAlpha($shadow, $x, $y + .7 * $ry))->toEqualWithDelta($middle, .00001)
    ->and(sampleGroundShadowAlpha($shadow, $x, $y - .7 * $ry))->toEqualWithDelta($middle, .00001);
})->with([32, 190, 600]);

it('keeps arena colour and strength while contact cores stay soft and bounded', function () {
  writeGroundShadowArt($this->root . '/Feet.png', [[60, 10, 80, 130], [60, 140, 20, 40], [120, 140, 20, 40]]);
  $layout = new BattleCanvasLayout(1350, 720, shadow: new BattlerShadowStyle(PresentationColor::rgb(10, 20, 30), opacity: .5));
  $color = PresentationColor::rgb(25, 18, 12);
  $arena = new BattleArenaDefinition('Synthetic', new CanvasImage('back', 'Back.png', new CanvasRectangle(0, 0, 1350, 720)),
    shadowLight: new \Ichiloto\Engine\Battle\Presentation\BattleShadowLight(1.4, $color));
  $shadow = BattlerGroundContact::compose('shadow', 500, 500, 190, false, 190, 0, BattlerContact::SHADOW,
    $layout, $arena, null, $this->root, opacity: .6,
    drawn: new CanvasImage('body', 'Feet.png', new CanvasRectangle(400, 300, 200, 200)))['composites'][0];
  expect($shadow->opacity)->toEqualWithDelta(.42, .00001)->and(count($shadow->operations))->toBeGreaterThan(1);
  foreach ($shadow->operations as $operation) {
    expect($operation->data['masks'])->toBe([])->and($operation->data['brush']['stops'][0]['color'])->toBe($color->toArray())
      ->and($operation->data['brush']['stops'][array_key_last($operation->data['brush']['stops'])]['opacity'])->toBe(0.0);
  }
  $first = $shadow->operations[0]->data['brush'];
  $alpha = sampleGroundShadowAlpha($shadow, $shadow->destination->x + $first['center'][0], $shadow->destination->y + $first['center'][1]);
  expect($alpha)->toBeGreaterThan(0)->and($alpha)->toBeLessThanOrEqual(.42);
  expect(\Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeBudget::getWork([$shadow]))
    ->toBeLessThan(\Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeBudget::MAX_WORK);
});
