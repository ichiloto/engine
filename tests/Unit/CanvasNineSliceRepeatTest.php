<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

it('preserves legacy stretched nine-slice geometry, ids, layer and clip', function () {
  $texture = new CanvasNineSlice('synthetic.png', new SpriteSourceRect(7, 11, 20, 12), 4, 3, 6, 2, 2);
  $clip = new CanvasRectangle(1, 2, 8, 6);
  $images = $texture->images('legacy', new CanvasRectangle(0.25, 0.5, 17.5, 12.25), -3, $clip);
  $expected = [];
  foreach ([[11, 3, 0.5, 1.5], [14, 7, 2.0, 9.75], [21, 2, 11.75, 1.0]] as $row => [$sy, $sh, $y, $h]) {
    foreach ([[7, 4, 0.25, 2.0], [11, 10, 2.25, 12.5], [21, 6, 14.75, 3.0]] as $column => [$sx, $sw, $x, $w]) {
      $expected[] = ['id' => "legacy-{$row}-{$column}", 'asset' => 'synthetic.png',
        'destination' => ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h], 'layer' => -3,
        'sourceRect' => ['x' => $sx, 'y' => $sy, 'width' => $sw, 'height' => $sh], 'clipRect' => $clip->toArray()];
    }
  }
  expect(array_map(fn($image) => $image->toArray(), $images))->toBe($expected);
});

it('retains fixed cuts and authored offsets at both densities without stretching any piece', function (int $density) {
  $source = new SpriteSourceRect(10, 20, 20 * $density, 8 * $density);
  $texture = new CanvasNineSlice('synthetic.png', $source, 3 * $density, 2 * $density,
    5 * $density, $density, $density);
  $sample = new SpriteSourceRect(10 + 7 * $density, 20, 4 * $density, 8 * $density);
  $destination = new CanvasRectangle(0.25, 0.5, 20, 8);
  $clip = new CanvasRectangle(0, 0, 30, 20);
  $images = $texture->composeRepeatedXImages('repeat', $destination, 17, $clip, $sample);
  expect($images)->toHaveCount(15);
  foreach ([[20, 2, 0.5], [20 + 2 * $density, 5, 2.5], [20 + 7 * $density, 1, 7.5]] as $row => [$sy, $height, $y]) {
    $pieces = array_slice($images, $row * 5, 5);
    foreach ([[10, 3, 0.25], [$sample->x, 4, 3.25], [$sample->x, 4, 7.25],
      [$sample->x, 4, 11.25], [10 + 15 * $density, 5, 15.25]] as $column => [$sx, $width, $x]) {
      $piece = $pieces[$column];
      expect($piece->destination->toArray())->toBe(['x' => $x, 'y' => $y, 'width' => (float)$width, 'height' => (float)$height])
        ->and($piece->sourceRect->toArray())->toBe(['x' => $sx, 'y' => $sy, 'width' => $width * $density, 'height' => $height * $density])
        ->and($piece->layer)->toBe(17)->and($piece->asset)->toBe('synthetic.png')->and($piece->clipRect)->toBe($clip);
      if ($column < 4) {
        expect($piece->destination->x + $piece->destination->width)->toBe($pieces[$column + 1]->destination->x);
      }
    }
  }
  expect(array_unique(array_column(array_map(fn($image) => $image->toArray(), $images), 'id')))->toHaveCount(15);
  new PresentationCanvas(30, 20, $images);
})->with([1, 2]);

it('keeps the fractional final sample at authored density and clips its tail before the right cap', function (int $density) {
  $texture = new CanvasNineSlice('synthetic.png', new SpriteSourceRect(9, 13, 18 * $density, 6 * $density),
    2 * $density, 0, 4 * $density, 0, $density);
  $sample = new SpriteSourceRect(9 + 6 * $density, 13, 3 * $density, 6 * $density);
  $images = $texture->composeRepeatedXImages('tail', new CanvasRectangle(10.25, 20.5, 14.75, 6), 4, repeatSource: $sample);
  expect($images)->toHaveCount(5);
  foreach ([1, 2, 3] as $index) {
    expect($images[$index]->sourceRect)->toEqual($sample)
      ->and($images[$index]->destination->width)->toBe(3.0)
      ->and($images[$index]->destination->height)->toBe(6.0)
      ->and($images[$index]->destination->x)->toBe(12.25 + ($index - 1) * 3);
  }
  expect($images[1]->clipRect)->toBeNull()->and($images[2]->clipRect)->toBeNull()
    ->and($images[3]->clipRect->toArray())->toBe(['x' => 18.25, 'y' => 20.5, 'width' => 2.75, 'height' => 6.0])
    ->and($images[4]->destination->toArray())->toBe(['x' => 21.0, 'y' => 20.5, 'width' => 4.0, 'height' => 6.0]);
  new PresentationCanvas(40, 40, $images);
})->with([1, 2]);

it('defaults to the whole middle strip and leaves the tail source and destination unscaled', function () {
  $texture = new CanvasNineSlice('synthetic.png', new SpriteSourceRect(5, 7, 14, 6), 3, 0, 3);
  $images = $texture->composeRepeatedXImages('whole', new CanvasRectangle(0, 0, 24, 6), 0);
  expect($images)->toHaveCount(5);
  foreach ([1, 2, 3] as $index) {
    expect($images[$index]->sourceRect->toArray())->toBe(['x' => 8, 'y' => 7, 'width' => 8, 'height' => 6])
      ->and($images[$index]->destination->width)->toBe(8.0);
  }
  expect($images[3]->clipRect->toArray())->toBe(['x' => 19.0, 'y' => 0.0, 'width' => 2.0, 'height' => 6.0]);
});

it('requires caller surface margin for fractional tails with zero or small right caps', function (int $density) {
  foreach ([0, 1] as $right) {
    $texture = new CanvasNineSlice('synthetic.png', new SpriteSourceRect(2, 3, 12 * $density, 6 * $density),
      right: $right * $density, density: $density);
    $sample = new SpriteSourceRect(2 + 4 * $density, 3, 4 * $density, 6 * $density);
    $images = $texture->composeRepeatedXImages('margin', new CanvasRectangle(0, 0, 5.25, 6), 0,
      repeatSource: $sample);
    $tail = $images[1];
    expect($images)->toHaveCount(2 + $right)->and($tail->sourceRect)->toEqual($sample)
      ->and($tail->destination->toArray())->toBe(['x' => 4.0, 'y' => 0.0, 'width' => 4.0, 'height' => 6.0])
      ->and($tail->clipRect->toArray())->toBe(['x' => 4.0, 'y' => 0.0, 'width' => 1.25 - $right, 'height' => 6.0]);
    expect(fn() => new PresentationCanvas(6, 6, $images))->toThrow(InvalidArgumentException::class, 'within the canvas');
    expect(new PresentationCanvas(8, 6, $images)->images)->toHaveCount(2 + $right);
  }
})->with([1, 2]);

it('intersects the tail with caller clipping and omits a disjoint tail without replacing other clips', function (int $density) {
  $texture = new CanvasNineSlice('synthetic.png', new SpriteSourceRect(0, 0, 18 * $density, 6 * $density),
    2 * $density, 0, 4 * $density, 0, $density);
  $sample = new SpriteSourceRect(6 * $density, 0, 3 * $density, 6 * $density);
  $destination = new CanvasRectangle(10.25, 20.5, 14.75, 6);
  $clip = new CanvasRectangle(19.25, 21.5, 10, 2);
  $images = $texture->composeRepeatedXImages('clip', $destination, 0, $clip, $sample);
  expect($images[3]->clipRect->toArray())->toBe(['x' => 19.25, 'y' => 21.5, 'width' => 1.75, 'height' => 2.0]);
  foreach ([0, 1, 2, 4] as $index) { expect($images[$index]->clipRect)->toBe($clip); }
  foreach ([new CanvasRectangle(21, 20, 1, 8), new CanvasRectangle(18, 27, 3, 1)] as $disjoint) {
    $hidden = $texture->composeRepeatedXImages('hidden', $destination, 0, $disjoint, $sample);
    expect($hidden)->toHaveCount(4);
    foreach ($hidden as $image) { expect($image->clipRect)->toBe($disjoint); }
  }
})->with([1, 2]);

it('supports exact cap-only and tiny centre destinations while keeping authored minimum dimensions', function (int $density) {
  $texture = new CanvasNineSlice('synthetic.png', new SpriteSourceRect(0, 0, 18 * $density, 6 * $density),
    4 * $density, 0, 6 * $density, 0, $density);
  $caps = $texture->composeRepeatedXImages('caps', new CanvasRectangle(0.25, 0.5, 10, 6), 0);
  expect($caps)->toHaveCount(2)->and($caps[0]->destination->width)->toBe(4.0)
    ->and($caps[1]->destination->x)->toBe(4.25)->and($caps[1]->destination->width)->toBe(6.0);
  $tiny = $texture->composeRepeatedXImages('tiny', new CanvasRectangle(0.25, 0.5, 10.125, 6), 0);
  expect($tiny)->toHaveCount(3)->and($tiny[1]->destination->width)->toBe(8.0)
    ->and($tiny[1]->clipRect->width)->toBe(0.125)->and($tiny[2]->destination->x)->toBe(4.375);
  expect(fn() => $texture->composeRepeatedXImages('small', new CanvasRectangle(0, 0, 9.875, 6), 0))
    ->toThrow(InvalidArgumentException::class, 'fixed caps');
  $minimum = new CanvasNineSlice('synthetic.png', $texture->source, $texture->left, 0, $texture->right,
    density: $density, minimumWidth: 12, minimumHeight: 6);
  expect($minimum->composeRepeatedXImages('minimum', new CanvasRectangle(0, 0, 12, 6), 0))->toHaveCount(3);
  expect(fn() => $minimum->composeRepeatedXImages('small', new CanvasRectangle(0, 0, 11.875, 6), 0))
    ->toThrow(InvalidArgumentException::class, 'minimum');
  foreach ([5.5, 6.5] as $height) {
    expect(fn() => $texture->composeRepeatedXImages('height', new CanvasRectangle(0, 0, 18, $height), 0))
      ->toThrow(InvalidArgumentException::class, 'exact source height');
  }
})->with([1, 2]);

it('accepts samples touching either middle edge and refuses caps or partial-height crops', function () {
  $texture = new CanvasNineSlice('synthetic.png', new SpriteSourceRect(10, 20, 20, 8), 3, 0, 5);
  $destination = new CanvasRectangle(0, 0, 20, 8);
  foreach ([new SpriteSourceRect(13, 20, 1, 8), new SpriteSourceRect(24, 20, 1, 8)] as $sample) {
    expect($texture->composeRepeatedXImages('edge', $destination, 0, repeatSource: $sample))->toHaveCount(14);
  }
  foreach ([new SpriteSourceRect(12, 20, 2, 8), new SpriteSourceRect(24, 20, 2, 8),
    new SpriteSourceRect(13, 19, 2, 8), new SpriteSourceRect(13, 21, 2, 8), new SpriteSourceRect(13, 20, 2, 7)] as $sample) {
    expect(fn() => $texture->composeRepeatedXImages('invalid', $destination, 0, repeatSource: $sample))
      ->toThrow(InvalidArgumentException::class, 'full-height crop');
  }
  $noMiddle = new CanvasNineSlice('synthetic.png', new SpriteSourceRect(0, 0, 8, 6), 3, 0, 5);
  expect(fn() => $noMiddle->composeRepeatedXImages('invalid', new CanvasRectangle(0, 0, 8, 6), 0))
    ->toThrow(InvalidArgumentException::class, 'positive source middle');
  expect(fn() => new SpriteSourceRect(0, 0, 0, 6))->toThrow(InvalidArgumentException::class);
  expect(fn() => new SpriteSourceRect(0, 0, 1, 0))->toThrow(InvalidArgumentException::class);
});

it('handles missing caps and zero-height middle rows without emitting degenerate patches', function () {
  $texture = new CanvasNineSlice('synthetic.png', new SpriteSourceRect(0, 0, 4, 6), 0, 2, 0, 4, 2);
  $images = $texture->composeRepeatedXImages('rows', new CanvasRectangle(0, 0, 4, 3), 0);
  expect($images)->toHaveCount(4);
  foreach ($images as $image) {
    expect($image->destination->width)->toBe(2.0)->and($image->clipRect)->toBeNull();
  }
  expect($images[0]->destination->height)->toBe(1.0)->and($images[2]->destination->height)->toBe(2.0);
});

it('requires finite bounded positive destination and clip rectangles', function () {
  foreach ([NAN, INF, -INF] as $value) {
    foreach (range(0, 3) as $axis) {
      $values = [0, 0, 4, 6];
      $values[$axis] = $value;
      expect(fn() => new CanvasRectangle(...$values))->toThrow(InvalidArgumentException::class, 'finite');
    }
  }
  foreach ([[0, 0, 0, 6], [0, 0, 4, 0], [-1, 0, 4, 6], [0, 0, 16385, 6]] as $values) {
    expect(fn() => new CanvasRectangle(...$values))->toThrow(InvalidArgumentException::class);
  }
});

it('accepts exactly 1024 image pieces and refuses the next or huge expansion before image construction', function (int $density) {
  $texture = new CanvasNineSlice('synthetic.png', new SpriteSourceRect(0, 0, 3, 4), 1, 0, 1, 0, $density);
  $images = $texture->composeRepeatedXImages('limit', new CanvasRectangle(0, 0, 1024 / $density, 4 / $density), 0);
  expect($images)->toHaveCount(1024);
  new PresentationCanvas(1024, 4, $images);
  foreach ([1024.25 / $density, 16384.0] as $width) {
    // This ID would fail on the first CanvasImage, proving the budget check runs first.
    expect(fn() => $texture->composeRepeatedXImages(str_repeat('x', 257),
      new CanvasRectangle(0, 0, $width, 4 / $density), 0))
      ->toThrow(InvalidArgumentException::class, 'expansion exceeds');
  }
})->with([1, 2]);

it('uses the caller remaining budget for all cut rows and cannot claim a fresh allowance through clipping', function () {
  $texture = new CanvasNineSlice('synthetic.png', new SpriteSourceRect(0, 0, 3, 3), 1, 1, 1, 1);
  $destination = new CanvasRectangle(0, 0, 5, 3);
  expect($texture->composeRepeatedXImages('remaining', $destination, 0, remainingImageBudget: 15))->toHaveCount(15);
  foreach ([0, 14] as $budget) {
    expect(fn() => $texture->composeRepeatedXImages('remaining', $destination, 0,
      new CanvasRectangle(100, 100, 1, 1), remainingImageBudget: $budget))
      ->toThrow(InvalidArgumentException::class, 'expansion exceeds');
  }
  foreach ([-1, 1025, PHP_INT_MAX] as $budget) {
    expect(fn() => $texture->composeRepeatedXImages('budget', $destination, 0, remainingImageBudget: $budget))
      ->toThrow(InvalidArgumentException::class, '0..1024');
  }
});

it('preflights synthetic repeated crops with ordinary image pieces and deduplicated source regions', function () {
  $root = createTestDirectory('canvas-repeat-preflight-');
  file_put_contents($root . '/synthetic.png', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', 32, 16));
  $texture = new CanvasNineSlice('synthetic.png', new SpriteSourceRect(2, 3, 20, 8), 3, 0, 5);
  $sample = new SpriteSourceRect(9, 3, 2, 8);
  $images = $texture->composeRepeatedXImages('preflight', new CanvasRectangle(0, 0, 100.125, 8), 0,
    repeatSource: $sample);
  $budget = CanvasImagePreflight::inspect($images, $root);
  expect($images)->toHaveCount(49)->and($budget['sources'])->toBe(['synthetic.png' => 32 * 16 * 4])
    ->and($budget['regions'])->toHaveCount(3);
  new PresentationCanvas(104, 8, $images);
});
