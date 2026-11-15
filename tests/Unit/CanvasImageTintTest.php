<?php

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageTint;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->root = \getTestTemporaryDirectory() . '/ichiloto-tint-' . bin2hex(random_bytes(6));
  mkdir($this->root);
  writeTestPng($this->root . '/pose.png', 16, 12);
});

afterEach(function () {
  unlink($this->root . '/pose.png');
  rmdir($this->root);
});

it('masks a tint to the displayed crop while preserving placement clipping opacity and depth', function () {
  $bounds = new CanvasRectangle(10.25, 20.5, 31.5, 45.75);
  $clip = new CanvasRectangle(12, 25, 20, 30);
  $image = new CanvasImage('actor', 'pose.png', $bounds, 100, new SpriteSourceRect(8, 3, 8, 6), .4, $clip);
  $tint = CanvasImageTint::compose('damage', $image, PresentationColor::rgb(255, 96, 96), .16, $this->root);
  expect($tint->width)->toBe(32)->and($tint->height)->toBe(46)->and($tint->destination)->toBe($bounds)
    ->and($tint->clipRect)->toBe($clip)->and($tint->opacity)->toBe(.4)->and($tint->layer)->toBe(101)
    ->and($tint->operations)->toHaveCount(1)
    ->and($tint->operations[0]->data)->toBe(['type' => 'fill', 'opacity' => .16, 'blend' => 'source_over',
      'masks' => [['type' => 'image_alpha', 'invert' => false, 'asset' => 'pose.png',
        'destination' => ['x' => 0.0, 'y' => 0.0, 'width' => 32.0, 'height' => 46.0],
        'source' => ['x' => .5, 'y' => .25, 'width' => .5, 'height' => .5]]],
      'destination' => ['x' => 0.0, 'y' => 0.0, 'width' => 32.0, 'height' => 46.0],
      'brush' => ['type' => 'solid', 'color' => ['kind' => 'rgb', 'r' => 255, 'g' => 96, 'b' => 96]]]);
  expect(CanvasImagePreflight::inspect([$image], $this->root, [$tint])['sources'])->toHaveCount(1);
});

it('reads current replacement dimensions instead of retaining a stale normalized crop', function () {
  $image = new CanvasImage('actor', 'pose.png', new CanvasRectangle(0, 0, 48, 96), 100,
    new SpriteSourceRect(8, 3, 8, 6));
  $first = CanvasImageTint::compose('damage', $image, PresentationColor::ansi16(1), .2, $this->root);
  unlink($this->root . '/pose.png');
  writeTestPng($this->root . '/pose.png', 32, 24);
  $second = CanvasImageTint::compose('damage', $image, PresentationColor::ansi16(1), .2, $this->root);
  expect($first->operations[0]->data['masks'][0]['source']['x'])->toBe(.5)
    ->and($second->operations[0]->data['masks'][0]['source'])->toBe(['x' => .25, 'y' => .125, 'width' => .25, 'height' => .25]);
});

it('combines cropped masks at fractional placements with each subjects clip and tint order', function () {
  $layers = [['color' => PresentationColor::ansi16(1), 'strength' => .2],
    ['color' => PresentationColor::ansi16(2), 'strength' => .3]];
  $first = new CanvasImage('first', 'pose.png', new CanvasRectangle(10.25, 20.5, 31.5, 45.75), 100,
    new SpriteSourceRect(8, 3, 8, 6), clipRect: new CanvasRectangle(12, 25, 20, 30));
  $second = new CanvasImage('second', 'pose.png', new CanvasRectangle(80.75, 40.25, 20.25, 25.5), 100);
  $group = CanvasImageTint::combine('group', [
    CanvasImageTint::composeLayers('first-tint', $first, $layers, $this->root),
    CanvasImageTint::composeLayers('second-tint', $second, $layers, $this->root),
  ]);
  expect($group->destination)->toEqual(new CanvasRectangle(10, 20, 91, 47))
    ->and($group->operations)->toHaveCount(4)->and($group->clipRect)->toBeNull()
    ->and($group->layer)->toBe(101)->and($group->opacity)->toBe(1.0)
    ->and(array_column(array_column($group->operations, 'data'), 'opacity'))->toBe([.2, .3, .2, .3]);
  $firstFill = $group->operations[0]->data;
  $secondFill = $group->operations[2]->data;
  expect($firstFill['destination'])->toBe(['x' => .25, 'y' => .5, 'width' => 31.5, 'height' => 45.75])
    ->and($firstFill['masks'][0]['destination'])->toBe($firstFill['destination'])
    ->and($firstFill['masks'][0]['source'])->toBe(['x' => .5, 'y' => .25, 'width' => .5, 'height' => .5])
    ->and($firstFill['masks'][1]['contours'])->toBe([[[2.0, 5.0], [22.0, 5.0], [22.0, 35.0], [2.0, 35.0]]])
    ->and($secondFill['destination'])->toBe(['x' => 70.75, 'y' => 20.25, 'width' => 20.25, 'height' => 25.5])
    ->and($secondFill['masks'])->toHaveCount(1)
    ->and(CanvasImagePreflight::inspect([$first, $second], $this->root, [$group])['sources'])->toHaveCount(1);
});

it('preserves an individual surface exactly when it needs no batching', function () {
  $image = new CanvasImage('actor', 'pose.png', new CanvasRectangle(10.25, 20.5, 31.5, 45.75), 100,
    new SpriteSourceRect(8, 3, 8, 6), clipRect: new CanvasRectangle(12, 25, 20, 30));
  $tint = CanvasImageTint::compose('tint', $image, PresentationColor::ansi16(1), .2, $this->root);
  expect(CanvasImageTint::combine('group', [$tint])->toArray())->toBe([...$tint->toArray(), 'id' => 'group']);
});

it('refuses batching that changes alpha or depth or exceeds normal resource limits', function (string $case) {
  $image = new CanvasImage('actor', 'pose.png', new CanvasRectangle(10, 20, 32, 48), 100);
  $tint = CanvasImageTint::compose('tint', $image, PresentationColor::ansi16(1), .2, $this->root);
  $tints = match ($case) {
    'empty' => [],
    'untyped' => ['not a surface'],
    'faded' => [CanvasImageTint::compose('faded', new CanvasImage('actor', 'pose.png', $image->destination,
      100, opacity: .4), PresentationColor::ansi16(1), .2, $this->root)],
    'depth' => [$tint, new CanvasComposite('other', 32, 48, $image->destination, $tint->operations, 102)],
    'list count' => array_fill(0, 257, $tint),
    'operation count' => [new CanvasComposite('many-first', 32, 48, $image->destination,
      array_fill(0, 129, $tint->operations[0]), 101), new CanvasComposite('many-second', 32, 48,
      $image->destination, array_fill(0, 129, $tint->operations[0]), 101)],
    'raster' => [$tint, new CanvasComposite('far', 32, 48, new CanvasRectangle(8000, 20, 32, 48), $tint->operations, 101)],
    'image operation' => [new CanvasComposite('image', 32, 48, $image->destination,
      [new CanvasCompositeOperation(['type' => 'image', 'asset' => 'pose.png',
        'destination' => ['x' => 0, 'y' => 0, 'width' => 32, 'height' => 48]])], 101)],
    'unmasked fill' => [new CanvasComposite('fill', 32, 48, $image->destination,
      [new CanvasCompositeOperation(['type' => 'fill', 'brush' => ['type' => 'solid', 'color' => PresentationColor::ansi16(1)->toArray()],
        'destination' => ['x' => 0, 'y' => 0, 'width' => 32, 'height' => 48]])], 101)],
  };
  expect(fn() => CanvasImageTint::combine('group', $tints))->toThrow(InvalidArgumentException::class);
})->with(['empty', 'untyped', 'faded', 'depth', 'list count', 'operation count', 'raster', 'image operation', 'unmasked fill']);

it('samples whole-image alpha when no frame crop is selected', function () {
  $image = new CanvasImage('actor', 'pose.png', new CanvasRectangle(0, 0, 48, 96));
  $tint = CanvasImageTint::compose('healing', $image, PresentationColor::ansi16(2), .2, $this->root);
  expect($tint->operations[0]->data['masks'][0])->not->toHaveKey('source');
});

it('rejects out-of-bounds crops before sending a misleading mask to the renderer', function () {
  $image = new CanvasImage('actor', 'pose.png', new CanvasRectangle(0, 0, 48, 96), 100,
    new SpriteSourceRect(8, 3, 9, 6));
  expect(fn() => CanvasImageTint::compose('damage', $image, PresentationColor::ansi16(1), .2, $this->root))
    ->toThrow(RuntimeException::class, 'crop exceed image bounds');
});

it('refuses malformed or excessive tint layers without weakening composite admission', function (array $layers) {
  $image = new CanvasImage('actor', 'pose.png', new CanvasRectangle(0, 0, 48, 96));
  expect(fn() => CanvasImageTint::composeLayers('tint', $image, $layers, $this->root))->toThrow(InvalidArgumentException::class);
})->with([
  [[]],
  [[['color' => 'red', 'strength' => .2]]],
  [[['color' => PresentationColor::ansi16(1), 'strength' => -1]]],
  [[['color' => PresentationColor::ansi16(1), 'strength' => NAN]]],
  [array_fill(0, 257, ['color' => PresentationColor::ansi16(1), 'strength' => .2])],
]);

it('retains exact rigid image geometry behind each viewport edge and maps tint source without stretching', function (float $dx, float $dy, bool $flipX, bool $flipY) {
  $image = new CanvasImage('body', 'pose.png', new CanvasRectangle(20, 20, 40, 40), 12,
    new SpriteSourceRect(4, 3, 8, 6), .75, brightness: .6, flipX: $flipX, flipY: $flipY);
  $moved = $image->translateClipped($dx, $dy, 80, 80);
  expect($moved)->not->toBeNull()->and($moved->destination->x)->toBe(20 + $dx)
    ->and($moved->destination->y)->toBe(20 + $dy)->and($moved->destination->width)->toBe(40.0)
    ->and($moved->destination->height)->toBe(40.0)->and($moved->sourceRect)->toBe($image->sourceRect)
    ->and($moved->brightness)->toBe(.6)->and($moved->flipX)->toBe($flipX)->and($moved->flipY)->toBe($flipY);
  $moved->assertWithin(80, 80);
  expect(CanvasImage::fromArray($moved->toArray())->toArray())->toBe($moved->toArray());
  $tint = CanvasImageTint::compose('tint', $moved, PresentationColor::ansi16(1), .2, $this->root);
  $tint->destination->assertWithin(80, 80);
  $source = $tint->operations[0]->data['masks'][0]['source'];
  $startX = ($tint->destination->x - $moved->destination->x) / 40;
  $startY = ($tint->destination->y - $moved->destination->y) / 40;
  $endX = $startX + $tint->destination->width / 40;
  $endY = $startY + $tint->destination->height / 40;
  // Coordinates preserve the source geometry to floating-point precision;
  // containment stays strict and must never be accepted by an epsilon gate.
  expect($source['x'])->toEqualWithDelta(.25 + ($flipX ? 1 - $endX : $startX) * .5, PHP_FLOAT_EPSILON)
    ->and($source['y'])->toEqualWithDelta(.25 + ($flipY ? 1 - $endY : $startY) * .5, PHP_FLOAT_EPSILON)
    ->and($source['width'])->toEqualWithDelta(($endX - $startX) * .5, PHP_FLOAT_EPSILON)
    ->and($source['height'])->toEqualWithDelta(($endY - $startY) * .5, PHP_FLOAT_EPSILON)
    ->and($source['x'])->toBeGreaterThanOrEqual(.25)->and($source['y'])->toBeGreaterThanOrEqual(.25)
    ->and($source['x'] + $source['width'])->toBeLessThanOrEqual(.75)
    ->and($source['y'] + $source['height'])->toBeLessThanOrEqual(.75)
    ->and($tint->opacity)->toBe(.75)->and($tint->layer)->toBe(13);
  expect(CanvasImagePreflight::inspect([$moved], $this->root, [$tint])['sources'])->toHaveCount(1);
})->with([[-30.25,0.0],[30.25,0.0],[0.0,-30.25],[0.0,30.25],[-30.25,-30.25]])->with([false,true])->with([false,true]);

it('drops fully clipped image paint without relocating the source or accepting malformed offsets', function () {
  $image = new CanvasImage('body', 'pose.png', new CanvasRectangle(20, 20, 40, 40));
  foreach ([[-100,0],[100,0],[0,-100],[0,100]] as [$x,$y]) { expect($image->translateClipped($x,$y,80,80))->toBeNull(); }
  expect(fn() => $image->translateClipped(INF,0,80,80))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $image->translateClipped(0,0,0,80))->toThrow(InvalidArgumentException::class);
});

it('retains pose attachment points and aquatic fade through rigid clipping and image tone', function () {
  $fade = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageBottomFade([.7,.75],.1);
  $pose = new \Ichiloto\Engine\Battle\Presentation\BattlerPose('pose.png', attachmentPoints: [
    ['cell' => 0, 'name' => 'release', 'point' => ['x' => .25, 'y' => .5]]]);
  $image = new CanvasImage('body','pose.png',new CanvasRectangle(20,20,40,40),12,
    new SpriteSourceRect(0,0,16,12),bottomFade: $fade);
  $before = $pose->projectAttachmentPoints($image)['release'];
  $moved = $image->translateClipped(-30.25,5.5,80,80);
  $after = $pose->projectAttachmentPoints($moved)['release'];
  expect($after->x - $before->x)->toBe(-30.25)->and($after->y - $before->y)->toBe(5.5)
    ->and($moved->bottomFade)->toBe($fade);
  $toned = \Ichiloto\Engine\Battle\Presentation\BattleEffectTone::applyToImage($moved,.4);
  expect($toned->destination)->toBe($moved->destination)->and($toned->sourceRect)->toBe($moved->sourceRect)
    ->and($toned->clipRect)->toBe($moved->clipRect)->and($toned->pivot)->toBe($moved->pivot)
    ->and($toned->rotation)->toBe(0)->and($toned->bottomFade)->toBe($fade)->and($toned->brightness)->toBe(.4);
});

it('keeps fractional tint source intervals contained through clipping and mirroring', function (bool $flipX, bool $flipY) {
  unlink($this->root . '/pose.png');
  writeTestPng($this->root . '/pose.png', 31, 23);
  $x = 212.5332428993514; $y = 94.4111821086262; $width = 162.5; $height = 80.5;
  $bounds = new CanvasRectangle($x, $y, $width, $height);
  $clips = [
    new CanvasRectangle(0, 0, 1350, 720),
    new CanvasRectangle($x + $width / 4, 0, 1350 - $x - $width / 4, 720),
    new CanvasRectangle(0, 0, $x + $width * .75, 720),
    new CanvasRectangle(0, $y + $height / 4, 1350, 720 - $y - $height / 4),
    new CanvasRectangle(0, 0, 1350, $y + $height * .75),
    new CanvasRectangle($x + $width / 4, $y + $height / 4, $width / 2, $height / 2),
  ];
  foreach ([null, new SpriteSourceRect(5, 4, 26, 19)] as $crop) {
    foreach ($clips as $index => $clip) {
      $image = new CanvasImage('fractional', 'pose.png', $bounds, 7, $crop, .6, $clip,
        flipX: $flipX, flipY: $flipY, rotation: 0,
        pivot: new \Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot(.5, .5));
      $tint = CanvasImageTint::compose('tint', $image, PresentationColor::ansi16(1), .16, $this->root);
      $data = $tint->operations[0]->data;
      $mask = $data['masks'][0];
      $source = $mask['source'] ?? ['x' => 0.0, 'y' => 0.0, 'width' => 1.0, 'height' => 1.0];
      expect($source['x'])->toBeGreaterThanOrEqual(0)
        ->and($source['y'])->toBeGreaterThanOrEqual(0)
        ->and($source['width'])->toBeGreaterThan(0)
        ->and($source['height'])->toBeGreaterThan(0)
        ->and($source['x'] + $source['width'])->toBeLessThanOrEqual(1)
        ->and($source['y'] + $source['height'])->toBeLessThanOrEqual(1);
      $left = max($x, $clip->x); $top = max($y, $clip->y);
      $right = min($x + $width, $clip->x + $clip->width);
      $bottom = min($y + $height, $clip->y + $clip->height);
      $sourceX = $crop?->x ?? 0; $sourceY = $crop?->y ?? 0;
      $sourceWidth = $crop?->width ?? 31; $sourceHeight = $crop?->height ?? 23;
      $expectedX = ($sourceX + ($flipX ? ($x + $width - $right) : ($left - $x)) * $sourceWidth / $width) / 31;
      $expectedY = ($sourceY + ($flipY ? ($y + $height - $bottom) : ($top - $y)) * $sourceHeight / $height) / 23;
      expect($source['x'])->toEqualWithDelta($expectedX, 1e-14)
        ->and($source['y'])->toEqualWithDelta($expectedY, 1e-14)
        ->and($source['width'])->toEqualWithDelta(($right - $left) / $width * $sourceWidth / 31, 1e-14)
        ->and($source['height'])->toEqualWithDelta(($bottom - $top) / $height * $sourceHeight / 23, 1e-14)
        ->and($tint->destination->x)->toBe($left)->and($tint->destination->y)->toBe($top)
        ->and($tint->opacity)->toBe(.6)->and($tint->layer)->toBe(8)
        ->and(CanvasImagePreflight::inspect([$image], $this->root, [$tint])['sources'])->toHaveCount(1);
      $wire = json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
      expect(new CanvasCompositeOperation($wire)->data)->toBe($data);
      if ($index === 0) {
        expect($tint->destination)->toBe($bounds);
        if ($crop === null) { expect($mask)->not->toHaveKey('source'); }
      }
    }
  }
})->with([false, true])->with([false, true]);

it('still rejects genuinely out-of-range normalized tint masks at the descriptor boundary', function (array $source) {
  expect(fn() => new CanvasCompositeOperation([
    'type' => 'fill', 'destination' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 12],
    'brush' => ['type' => 'solid', 'color' => PresentationColor::ansi16(1)->toArray()],
    'masks' => [['type' => 'image_alpha', 'asset' => 'pose.png',
      'destination' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 12], 'source' => $source]],
  ]))->toThrow(InvalidArgumentException::class);
})->with([
  [['x' => 0, 'y' => 0, 'width' => 1 + PHP_FLOAT_EPSILON, 'height' => 1]],
  [['x' => -PHP_FLOAT_EPSILON, 'y' => 0, 'width' => 1, 'height' => 1]],
  [['x' => .9, 'y' => 0, 'width' => .2, 'height' => 1]],
  [['x' => 0, 'y' => 0, 'width' => INF, 'height' => 1]],
]);
