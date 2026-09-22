<?php

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-canvas-budget-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  // Header-only fixtures exercise PHP preflight, not the native PNG decoder.
  $this->png = function (string $name, int $width, int $height): void {
    file_put_contents($this->root . '/' . $name, "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', $width, $height));
  };
});

afterEach(function () {
  foreach (glob($this->root . '/*') ?: [] as $file) { unlink($file); }
  rmdir($this->root);
});

it('deduplicates canonical sources and guarded crops independently of destination opacity and clipping', function () {
  ($this->png)('sheet.png', 100, 80);
  symlink($this->root . '/sheet.png', $this->root . '/alias.png');
  $bounds = new CanvasRectangle(0, 0, 10, 10);
  $crop = new SpriteSourceRect(0, 0, 20, 30);
  $images = [
    new CanvasImage('first', 'sheet.png', $bounds, 0, $crop),
    new CanvasImage('alias', 'alias.png', $bounds, 0, $crop, 0, $bounds),
    new CanvasImage('other-crop', 'sheet.png', $bounds, 0, new SpriteSourceRect(20, 0, 20, 30)),
  ];
  $budget = CanvasImagePreflight::inspect($images, $this->root);
  expect($budget['sources'])->toBe(['sheet.png' => 100 * 80 * 4])
    ->and($budget['regions'])->toHaveCount(2)
    ->and(array_sum($budget['regions']))->toBe(2 * 24 * 34 * 4);
});

it('counts full decoded sources even for invisible one-pixel crops', function () {
  $images = [];
  foreach (['a.png', 'b.png'] as $name) {
    ($this->png)($name, 4096, 4096);
    $images[] = new CanvasImage($name, $name, new CanvasRectangle(0, 0, 1, 1), 0,
      new SpriteSourceRect(0, 0, 1, 1), 0);
  }
  expect(fn() => CanvasImagePreflight::inspect($images, $this->root))
    ->toThrow(RuntimeException::class, 'PNG sources');
});

it('rejects guarded regions independently when the decoded source still fits', function () {
  ($this->png)('large.png', 4096, 4096);
  $image = new CanvasImage('large', 'large.png', new CanvasRectangle(0, 0, 1, 1));
  expect(fn() => CanvasImagePreflight::inspect([$image], $this->root))
    ->toThrow(RuntimeException::class, 'prepared regions');
});

it('accounts for nine-slice regions and rejects source bounds before rendering', function () {
  ($this->png)('panel.png', 24, 24);
  $texture = new CanvasNineSlice('panel.png', new SpriteSourceRect(0, 0, 24, 24), 8, 8, 8, 8);
  $images = CanvasImagePreflight::textures([$texture]);
  $budget = CanvasImagePreflight::inspect($images, $this->root);
  expect($images)->toHaveCount(9)
    ->and(array_sum($budget['sources']))->toBe(24 * 24 * 4)
    ->and(array_sum($budget['regions']))->toBe(9 * 12 * 12 * 4);
  $invalid = new CanvasImage('invalid', 'panel.png', new CanvasRectangle(0, 0, 1, 1), 0,
    new SpriteSourceRect(24, 0, 1, 1));
  expect(fn() => CanvasImagePreflight::inspect([$invalid], $this->root))->toThrow(RuntimeException::class, 'bounds');
});

it('loads whole PNGs without authored source dimensions and reconciles borders after replacement', function () {
  $bounds = new CanvasRectangle(0, 0, 200, 100);
  foreach ([[32, 48], [8, 6], [100, 80]] as $revision => [$width, $height]) {
    ($this->png)('panel.png', $width, $height);
    // Header-only replacements have equal byte length; mark each revision explicitly without a timing-dependent sleep.
    touch($this->root . '/panel.png', 1700000000 + $revision);
    $texture = CanvasNineSlice::fromPng($this->root, 'panel.png', 12, 12, 12, 12);
    $images = $texture->images('panel', $bounds, 0);
    CanvasImagePreflight::inspect($images, $this->root);
    expect($texture->source->toArray())->toBe(['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height])
      ->and(array_sum(array_map(fn($image) => $image->destination->width * $image->destination->height, $images)))
      ->toEqualWithDelta($bounds->width * $bounds->height, 0.000001);
  }
  expect(fn() => CanvasNineSlice::fromPng($this->root, 'panel.png', left: -1))
    ->toThrow(InvalidArgumentException::class);
  expect(fn() => CanvasNineSlice::fromPng($this->root, 'missing.png'))->toThrow(RuntimeException::class);
});
