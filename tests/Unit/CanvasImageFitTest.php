<?php

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageFit;

it('contains source frames uniformly inside the authored box', function (int $sourceWidth, int $sourceHeight,
  float $width, float $height, float $expectedWidth, float $expectedHeight) {
  $size = CanvasImageFit::CONTAIN->getSize($sourceWidth, $sourceHeight, $width, $height);
  expect($size['width'])->toEqualWithDelta($expectedWidth, .000001)
    ->and($size['height'])->toEqualWithDelta($expectedHeight, .000001)
    ->and($size['width'] / $size['height'])->toEqualWithDelta($sourceWidth / $sourceHeight, .000001)
    ->and($size['width'])->toBeLessThanOrEqual($width)
    ->and($size['height'])->toBeLessThanOrEqual($height);
})->with([
  'wide cinematic' => [1280, 720, 1350.0, 720.0, 1280.0, 720.0],
  'portrait' => [24, 96, 144.0, 192.0, 48.0, 192.0],
  'square' => [8, 8, 144.0, 192.0, 144.0, 144.0],
  'fractional' => [7, 3, 11.5, 8.25, 11.5, 34.5 / 7],
]);

it('keeps legacy stretch sizing independent of the source aspect ratio', function () {
  expect(CanvasImageFit::parse('stretch')->getSize(1280, 720, 1350, 720))
    ->toBe(['width' => 1350.0, 'height' => 720.0]);
});

it('refuses malformed image fit intent', function (mixed $fit) {
  expect(fn() => CanvasImageFit::parse($fit))->toThrow(InvalidArgumentException::class);
})->with(['unknown' => 'cover', 'null' => null, 'numeric' => 1, 'descriptor' => [[]]]);

it('refuses unusable source dimensions and drawing boxes', function (int $sourceWidth, int $sourceHeight,
  float $width, float $height) {
  foreach (CanvasImageFit::cases() as $fit) {
    expect(fn() => $fit->getSize($sourceWidth, $sourceHeight, $width, $height))
      ->toThrow(InvalidArgumentException::class);
  }
})->with([
  'empty source' => [0, 8, 48.0, 48.0], 'negative source' => [8, -1, 48.0, 48.0],
  'empty box' => [8, 8, 0.0, 48.0], 'negative box' => [8, 8, 48.0, -1.0],
  'infinite box' => [8, 8, INF, 48.0], 'nan box' => [8, 8, 48.0, NAN],
]);
