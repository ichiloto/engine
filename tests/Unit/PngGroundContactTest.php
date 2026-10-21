<?php

use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattlerContact;
use Ichiloto\Engine\Battle\Presentation\BattlerGroundContact;
use Ichiloto\Engine\Battle\Presentation\BattlerShadowStyle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Sprites\PngGroundContact;

/**
 * Battlers' shadows follow where their art meets the ground, read from the
 * current image. Synthetic art only: opaque rectangles for legs, feet, hems
 * and lying bodies on a transparent frame.
 */

/** Writes a 200 by 200 transparent PNG with opaque rectangles [x, y, width, height]. */
function writeContactArt(string $path, array $rectangles): void
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

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-ground-contact-' . bin2hex(random_bytes(5));
  mkdir($this->root, 0777, true);
});

afterEach(function () {
  exec('rm -rf ' . escapeshellarg($this->root));
});

it('finds both feet of a staggered stance, the back foot higher, and leaves a hem hanging above them out', function () {
  // A body from 20 to 140, a front leg to 190 and a back leg to 170, and a coat hem tip hanging to 158 at the far left.
  writeContactArt($this->root . '/Stance.png', [[60, 20, 80, 120], [60, 140, 20, 50], [120, 140, 20, 30], [40, 120, 4, 38]]);
  $contact = PngGroundContact::getContact($this->root, 'Stance.png');
  $feet = $contact['patches'];

  expect($contact['top'])->toBe(.1)->and($contact['bottom'])->toBe(.95)
    ->and(array_map(static fn(array $patch): array => [$patch[0], $patch[1], $patch[2]], $feet))
    ->toBe([[.3, .4, .95], [.6, .7, .85]]);
});

it('returns a lying body\'s whole lower edge, in pieces that follow its slope', function () {
  // A body lying across the frame, its lower edge stepping down toward the right, a sword lying in front of it.
  writeContactArt($this->root . '/Lying.png', [[20, 100, 40, 30], [60, 100, 40, 40], [100, 100, 60, 50], [30, 150, 100, 6]]);
  $contact = PngGroundContact::getContact($this->root, 'Lying.png');
  $edge = $contact['edge'];

  expect(min(array_column($edge, 0)))->toBe(.1)->and(max(array_column($edge, 1)))->toBe(.8)
    // The sword in front is lowest; the body behind it reaches the ground higher up past its tip.
    ->and(max(array_column($edge, 2)))->toBe(.78)
    ->and(array_column($edge, 2))->toContain(.75)
    // A standing contact would be only what a plank laid under it touches.
    ->and(count($contact['patches']))->toBeLessThan(count($edge));
});

it('reads replaced art afresh and gives no answer for art it cannot read', function () {
  writeContactArt($this->root . '/Art.png', [[60, 20, 80, 160]]);
  $first = PngGroundContact::getContact($this->root, 'Art.png')['patches'];
  // Replace the art with a wider stance; the cache follows the file, not its name.
  writeContactArt($this->root . '/Art.png', [[60, 20, 80, 120], [40, 140, 20, 40], [140, 140, 20, 40]]);
  touch($this->root . '/Art.png', time() + 5);
  $second = PngGroundContact::getContact($this->root, 'Art.png')['patches'];
  file_put_contents($this->root . '/Broken.png', 'not a png');

  expect(min(array_column($first, 0)))->toBe(.3)
    ->and(min(array_column($second, 0)))->toBe(.2)->and(max(array_column($second, 1)))->toBe(.8)
    ->and(PngGroundContact::getContact($this->root, 'Broken.png'))->toBeNull()
    ->and(PngGroundContact::getContact($this->root, '../outside.png'))->toBeNull();
});

it('grounds a shadow at every foot of the art as drawn, joining them, and falls back to the feet point without art', function () {
  writeContactArt($this->root . '/Stance.png', [[60, 20, 80, 120], [60, 140, 20, 50], [120, 140, 20, 30]]);
  $layout = new BattleCanvasLayout(1350, 720, shadow: new BattlerShadowStyle(PresentationColor::rgb(10, 10, 20), opacity: .5));
  $arena = new BattleArenaDefinition('Field', new CanvasImage('field', 'field.png', new CanvasRectangle(0, 0, 1350, 720)));
  $drawn = new CanvasImage('art', 'Stance.png', new CanvasRectangle(400, 300, 100, 100), 100);
  $compose = fn(?CanvasImage $image, float $elevation = 0) => BattlerGroundContact::compose('shadow', 445, 395, 90, false, 100, $elevation,
    BattlerContact::SHADOW, $layout, $arena, null, $this->root, drawn: $image)['composites'][0];
  $shadow = $compose($drawn);
  $outline = $shadow->operations[0]->data['masks'][0]['contours'][0];
  $points = array_map(static fn(array $point): array => [$point[0] + $shadow->destination->x, $point[1] + $shadow->destination->y], $outline);

  // Its outline reaches both feet: the front at x 430..440 standing at 395, the back at 460..470 standing at 385.
  expect(min(array_column($points, 0)))->toBeLessThan(430.0)->and(max(array_column($points, 0)))->toBeGreaterThan(470.0)
    ->and(max(array_column($points, 1)))->toBeGreaterThan(395.0)->and(min(array_column($points, 1)))->toBeLessThan(385.0)
    ->and($shadow->layer)->toBeLessThan(100)->and($shadow->opacity)->toBe(.5)
    // A darker core under each foot.
    ->and(count($shadow->operations))->toBe(3)
    // In the air, or with art that cannot be read, it is one ellipse under the feet point.
    ->and($compose($drawn, .4)->operations[0]->data['brush']['type'])->toBe('radial')
    ->and($compose(new CanvasImage('art', 'Missing.png', new CanvasRectangle(400, 300, 100, 100), 100))->operations[0]->data['brush']['type'])
    ->toBe('radial');
});
