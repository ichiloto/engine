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
  float $elevation = 0): CanvasComposite
{
  $layout = new BattleCanvasLayout(1350, 720, shadow: new BattlerShadowStyle(PresentationColor::rgb(10, 10, 20), opacity: .5,
    flatness: .28));
  $arena = new BattleArenaDefinition('Field', new CanvasImage('field', 'field.png', new CanvasRectangle(0, 0, 1350, 720)));
  return BattlerGroundContact::compose('shadow', $destination->x + $destination->width / 2, $destination->y + $destination->height,
    90, false, $destination->width, $elevation, BattlerContact::SHADOW, $layout, $arena, null, $root,
    drawn: new CanvasImage('art', $asset, $destination, 100, flipX: $flipX))['composites'][0];
}

/** @return array{left: float, right: float, top: float, bottom: float} The shadow's outline in canvas units. */
function getGroundShadowExtent(CanvasComposite $shadow): array
{
  $outline = $shadow->operations[0]->data['masks'][0]['contours'][0];
  $xs = array_map(static fn(array $point): float => $point[0] + $shadow->destination->x, $outline);
  $ys = array_map(static fn(array $point): float => $point[1] + $shadow->destination->y, $outline);
  return ['left' => min($xs), 'right' => max($xs), 'top' => min($ys), 'bottom' => max($ys)];
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
