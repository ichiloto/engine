<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\Timelines\EffectFlightContact;
use Ichiloto\Engine\Battle\Presentation\BattleStraightShot;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

it('chooses a single ordered ray through staggered target bodies', function (bool $mirrored) {
  $origin = new Vector2($mirrored ? 100 : 0, 10);
  $near = new CanvasRectangle($mirrored ? 60 : 20, 14, 20, 9);
  $far = new CanvasRectangle($mirrored ? 5 : 75, 29, 20, 20);
  $path = BattleStraightShot::plan($origin, [$near, $far]);
  expect($path)->not->toBeNull();
  $exit = $path->getPositionAtProgress(1);
  $throughNear = $path->getPositionAtProgress(($near->x + $near->width / 2 - $origin->x) / ($exit->x - $origin->x));
  expect($throughNear->y)->toBeGreaterThan($near->y)->toBeLessThan($near->y + $near->height)
    ->and($exit->y)->toBeGreaterThanOrEqual($far->y)->toBeLessThanOrEqual($far->y + $far->height)
    ->and(count($path->getWaypoints()))->toBe(2)
    ->and(BattleStraightShot::plan($origin, [$far, $near]))->toBeNull();
})->with([false, true]);

it('reports when no straight ray reaches both targets', function () {
  expect(BattleStraightShot::plan(new Vector2(0, 50), [
    new CanvasRectangle(20, 5, 10, 10), new CanvasRectangle(80, 80, 10, 10),
  ]))->toBeNull();
});


it('aims a drawable ray through painted pixels of each cropped and clipped target', function (bool $mirrored) {
  if (!function_exists('imagecreatefrompng')) { test()->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
  $root = createTestDirectory('ichiloto-ray-contact-');
  $art = imagecreatetruecolor(30, 20);
  imagealphablending($art, false);
  imagesavealpha($art, true);
  imagefill($art, 0, 0, imagecolorallocatealpha($art, 0, 0, 0, 127));
  $paint = imagecolorallocate($art, 255, 255, 255);
  // Two disconnected painted areas inside a cropped cell, leaving a transparent centre.
  imagefilledrectangle($art, 11, 6, 13, 8, $paint);
  imagefilledrectangle($art, 16, 11, 18, 13, $paint);
  imagepng($art, $root . '/silhouette.png');
  $origin = new Vector2($mirrored ? 120 : 0, 50);
  $nearX = $mirrored ? 70 : 30;
  $farX = $mirrored ? 20 : 80;
  $crop = new SpriteSourceRect(10, 5, 10, 10);
  $near = new CanvasImage('near', 'silhouette.png', new CanvasRectangle($nearX, 45, 20, 20), sourceRect: $crop, flipX: $mirrored);
  $far = new CanvasImage('far', 'silhouette.png', new CanvasRectangle($farX, 40, 20, 20), sourceRect: $crop, flipX: $mirrored,
    clipRect: new CanvasRectangle($farX, 40, 20, 10));

  $path = BattleStraightShot::planVisible($origin, [$near, $far], $root);
  expect($path)->not->toBeNull();
  $arrival = $path->getPositionAtProgress(1);
  $readsPaint = static function (CanvasImage $image, Vector2 $point) use ($art): bool {
    $x = (int)floor(($point->x - $image->destination->x) / 2);
    $y = (int)floor(($point->y - $image->destination->y) / 2);
    return $x >= 0 && $x < 10 && $y >= 0 && $y < 10
      && imagecolorat($art, 10 + ($image->flipX ? 9 - $x : $x), 5 + $y) < 0x7F000000;
  };
  $hitsNear = false;
  for ($distance = .25; $distance < $path->distance; $distance += .25) {
    if ($readsPaint($near, $path->getPositionAtProgress($distance / $path->distance))) { $hitsNear = true; break; }
  }
  $heading = rad2deg(atan2($arrival->y - $origin->y, $arrival->x - $origin->x));
  expect($hitsNear)->toBeTrue()
    ->and($readsPaint($far, $arrival))->toBeTrue()
    ->and($arrival->y)->toBeLessThan(50.0)
    ->and($heading)->toEqualWithDelta(round($heading), .000001)
    ->and(BattleStraightShot::planVisible($origin, [$far, $near], $root))->toBeNull();
})->with([false, true]);

it('finishes a straight pierce inside the final painted body after passing an earlier target', function () {
  if (!function_exists('imagecreatefrompng')) { test()->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
  $root = createTestDirectory('ichiloto-ray-interior-');
  $art = imagecreatetruecolor(60, 20);
  imagealphablending($art, false);
  imagesavealpha($art, true);
  imagefill($art, 0, 0, imagecolorallocatealpha($art, 0, 0, 0, 127));
  $paint = imagecolorallocate($art, 255, 255, 255);
  imagefilledrectangle($art, 2, 8, 4, 11, $paint);
  imagefilledrectangle($art, 20, 4, 56, 15, $paint);
  imagepng($art, $root . '/body.png');

  $near = new CanvasImage('near', 'body.png', new CanvasRectangle(20, 10, 12, 4));
  $far = new CanvasImage('far', 'body.png', new CanvasRectangle(70, 2, 60, 20));
  $path = BattleStraightShot::planVisible(new Vector2(0, 12), [$near, $far], $root, EffectFlightContact::INTERIOR);
  expect($path)->not->toBeNull();
  $arrival = $path->getPositionAtProgress(1);
  expect($arrival->x)->toBeGreaterThan(105.0)->toBeLessThan(111.0)
    ->and($arrival->y)->toBeGreaterThan(6.0)->toBeLessThan(18.0)
    ->and(BattleStraightShot::planVisible(new Vector2(0, 12), [$far, $near], $root))->toBeNull();
});
