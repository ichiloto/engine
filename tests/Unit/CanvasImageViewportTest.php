<?php

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasImage, CanvasRectangle, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\{SpriteSourceRect, PresentationSpritePivot};
use Ichiloto\Engine\Rendering\Assets\{AssetHoldResource, AssetHoldCanvas};

it('retains an already bounded image without adding affine drawing requirements', function () {
  $image = new CanvasImage('bounded', 'replaceable.png', new CanvasRectangle(5, 7, 20, 30));
  expect($image->clipToViewport(100, 100))->toBe($image)->and($image->rotation)->toBeNull()->and($image->clipRect)->toBeNull();
});

it('clips only the viewport while retaining the full source preparation and a wire round trip', function (float $x, float $y) {
  $image = new CanvasImage('clipped', 'replaceable.png', new CanvasRectangle($x, $y, 80, 120, allowOutsideCanvas: true),
    layer: 7, sourceRect: new SpriteSourceRect(10, 20, 40, 60), opacity: .6, brightness: .7, flipX: true, flipY: true);
  $paint = $image->clipToViewport(100, 100);
  expect($paint)->not->toBeNull()->and($paint->destination)->toEqual($image->destination)
    ->and($paint->sourceRect)->toBe($image->sourceRect)->and($paint->flipX)->toBeTrue()->and($paint->flipY)->toBeTrue()
    ->and($paint->opacity)->toBe(.6)->and($paint->brightness)->toBe(.7)->and($paint->layer)->toBe(7);
  $paint->clipRect->assertWithin(100, 100);
  expect(CanvasImage::fromArray($paint->toArray()))->toEqual($paint);
  new PresentationCanvas(100, 100, [$paint]);
  $canvas = AssetHoldCanvas::fromCanvas(new PresentationCanvas(100, 100));
  expect(AssetHoldResource::fromCanvasImage($paint, $canvas)->getKey())
    ->toBe(AssetHoldResource::fromCanvasImage($image, $canvas)->getKey());
})->with([[-30.0, -40.0], [70.0, -40.0], [-30.0, 70.0], [70.0, 70.0]]);

it('retains a rotated source pivot behind a clipped parent', function () {
  $image = new CanvasImage('rotated', 'replaceable.png', new CanvasRectangle(-20, 5, 80, 120, allowOutsideCanvas: true),
    sourceRect: new SpriteSourceRect(0, 0, 40, 60), rotation: 90, pivot: new PresentationSpritePivot(.25, .75));
  $paint = $image->clipToViewport(100, 100);
  expect($paint)->not->toBeNull()->and($paint->destination)->toEqual($image->destination)
    ->and($paint->rotation)->toBe(90)->and($paint->pivot)->toBe($image->pivot)->and($paint->sourceRect)->toBe($image->sourceRect);
  $paint->clipRect->assertWithin(100, 100);
});

it('omits a wholly hidden paint while leaving logical geometry intact', function () {
  $bounds = new CanvasRectangle(-100, 20, 40, 40, allowOutsideCanvas: true);
  $image = new CanvasImage('hidden', 'replaceable.png', $bounds);
  expect($image->clipToViewport(100, 100))->toBeNull()->and($image->destination)->toBe($bounds)->and($image->sourceRect)->toBeNull();
});

it('rejects an invalid parent viewport even for an otherwise bounded image', function () {
  $image = new CanvasImage('bounded', 'replaceable.png', new CanvasRectangle(5, 7, 20, 30));
  expect(fn() => $image->clipToViewport(0, 100))->toThrow(InvalidArgumentException::class);
});
