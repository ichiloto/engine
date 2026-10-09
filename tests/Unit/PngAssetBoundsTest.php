<?php

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetBounds;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-visible-' . bin2hex(random_bytes(6));
  mkdir($this->root);
  $this->readInChild = function (bool $withoutDecoder = false): array {
    $code = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; '
      . '$image = new \\Ichiloto\\Engine\\Rendering\\Presentation\\Canvas\\CanvasImage("image", "pixels.png", '
      . 'new \\Ichiloto\\Engine\\Rendering\\Presentation\\Canvas\\CanvasRectangle(0, 0, 100, 100)); '
      . 'echo json_encode(\\Ichiloto\\Engine\\Rendering\\Sprites\\PngAssetBounds::getVisibleBounds('
      . var_export($this->root, true) . ', $image));';
    $command = escapeshellarg(PHP_BINARY) . ' -n ' . ($withoutDecoder ? '-d disable_functions=imagecreatefrompng ' : '')
      . '-r ' . escapeshellarg($code);
    exec($command, $output, $exit);
    expect($exit)->toBe(0);
    return json_decode(implode('', $output), true, flags: JSON_THROW_ON_ERROR);
  };
  $this->writePixels = function (bool $palette = false, int $x = 2, int $y = 3, int $alpha = 0): void {
    if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
    $image = $palette ? imagecreate(10, 10) : imagecreatetruecolor(10, 10);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
    imagefilledrectangle($image, 0, 0, 9, 9, $transparent);
    $color = imagecolorallocatealpha($image, 100, 150, 200, $alpha);
    imagefilledrectangle($image, $x, $y, $x + 2, $y + 1, $color);
    imagepng($image, $this->root . '/pixels.png');
  };
});

afterEach(function () {
  foreach (glob($this->root . '/*') ?: [] as $file) { unlink($file); }
  rmdir($this->root);
});

it('reads truecolor and palette transparency without changing the image', function (bool $palette, bool $flipX, bool $flipY) {
  ($this->writePixels)($palette);
  $before = file_get_contents($this->root . '/pixels.png');
  $image = new CanvasImage('image', 'pixels.png', new CanvasRectangle(100, 200, 100, 200), flipX: $flipX, flipY: $flipY);
  $result = PngAssetBounds::getVisibleBounds($this->root, $image);
  expect($result['diagnostic'])->toBeNull()->and($result['bounds'])->toEqual(new CanvasRectangle(
    $flipX ? 150 : 120, $flipY ? 300 : 260, 30, 40))
    ->and(file_get_contents($this->root . '/pixels.png'))->toBe($before);
})->with([[false, false, false], [true, false, false], [false, true, false], [true, true, true]]);

it('respects cropped frames, clipping and very faint visible pixels', function () {
  ($this->writePixels)(false, 2, 3, 126);
  $image = new CanvasImage('image', 'pixels.png', new CanvasRectangle(0, 0, 60, 60),
    sourceRect: new SpriteSourceRect(1, 2, 6, 6), clipRect: new CanvasRectangle(20, 20, 30, 30));
  expect(PngAssetBounds::getVisibleBounds($this->root, $image)['bounds'])->toEqual(new CanvasRectangle(20, 20, 20, 10));
});

it('distinguishes transparent artwork from unavailable inspection', function () {
  ($this->writePixels)(false, 2, 3, 127);
  $image = new CanvasImage('image', 'pixels.png', new CanvasRectangle(0, 0, 100, 100));
  expect(PngAssetBounds::getVisibleBounds($this->root, $image))->toBe(['bounds' => null, 'diagnostic' => null]);
  unlink($this->root . '/pixels.png');
  $missing = PngAssetBounds::getVisibleBounds($this->root, $image);
  expect($missing['bounds'])->toBeNull()->and($missing['diagnostic'])->toContain('readable PNG');
});

it('reports a PNG that passes header preflight but cannot be decoded', function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
  file_put_contents($this->root . '/pixels.png', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', 10, 10));
  $result = ($this->readInChild)();
  expect($result['bounds'])->toBeNull()->and($result['diagnostic'])->toContain('could not decode');
});

it('reads changed same-dimension pixels and never freezes an import hash', function () {
  ($this->writePixels)();
  $image = new CanvasImage('image', 'pixels.png', new CanvasRectangle(0, 0, 100, 100));
  expect(PngAssetBounds::getVisibleBounds($this->root, $image)['bounds']->x)->toBe(20.0);
  ($this->writePixels)(false, 5, 6);
  expect(PngAssetBounds::getVisibleBounds($this->root, $image)['bounds'])->toEqual(new CanvasRectangle(50, 60, 30, 20));
});

it('reports absent optional decoding explicitly without requiring GD for runtime preflight', function () {
  \Tests\Support\Rendering\writeTestPng($this->root . '/pixels.png', 10, 10);
  $result = ($this->readInChild)(true);
  expect($result['bounds'])->toBeNull()->and($result['diagnostic'])->toContain('optional GD PNG support is not installed');
});
