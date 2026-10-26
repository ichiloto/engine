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
  $this->root = sys_get_temp_dir() . '/ichiloto-tint-' . bin2hex(random_bytes(6));
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
