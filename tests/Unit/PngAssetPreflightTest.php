<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Util\Debug;

function writePreflightTestPng(string $path, int $width, int $height): void
{
  $chunk = static fn(string $type, string $bytes): string => pack('N', strlen($bytes)) . $type . $bytes
    . pack('N', crc32($type . $bytes));
  file_put_contents($path, "\x89PNG\r\n\x1a\n"
    . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xAB\xBC\xCD\xFF", $width), $height)))
    . $chunk('IEND', ''));
}

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-png-cache-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  $this->cache = new ReflectionProperty(PngAssetPreflight::class, 'cache');
  $this->previousCache = $this->cache->getValue();
  $this->cache->setValue(null, []);
  $this->debugStatics = new ReflectionClass(Debug::class)->getStaticProperties();
  Debug::configure(['log_directory' => $this->root]);
});

afterEach(function () {
  $this->cache->setValue(null, $this->previousCache);
  foreach ($this->debugStatics as $name => $value) { new ReflectionProperty(Debug::class, $name)->setValue(null, $value); }
  foreach (glob($this->root . '/*') as $path) { unlink($path); }
  rmdir($this->root);
});

it('reuses a successful cached lookup until the file revision changes', function () {
  $path = $this->root . '/asset.png';
  writePreflightTestPng($path, 12, 8);
  touch($path, 1700000000);
  expect(PngAssetPreflight::inspect($this->root, 'asset.png'))->toBe(['width' => 12, 'height' => 8]);
  $cache = $this->cache->getValue();
  // Mark the loaded result to prove a repeated lookup returns cached data, without a production telemetry API.
  $cache[$path]['size'] = ['width' => 7, 'height' => 5];
  $this->cache->setValue(null, $cache);
  expect(PngAssetPreflight::inspect($this->root, 'asset.png'))->toBe(['width' => 7, 'height' => 5])
    ->and(PngAssetPreflight::getAvailableSize($this->root, 'asset.png'))->toBe(['width' => 7, 'height' => 5])
    ->and($this->cache->getValue())->toBe($cache);
  // Changing only mtime must invalidate that marked entry and read the real dimensions again.
  touch($path, 1700000002);
  expect(PngAssetPreflight::inspect($this->root, 'asset.png'))->toBe(['width' => 12, 'height' => 8]);
});

it('honors new dimensions at the same path after an artwork replacement', function () {
  $path = $this->root . '/asset.png';
  writePreflightTestPng($path, 12, 8);
  touch($path, 1700000000);
  expect(PngAssetPreflight::inspect($this->root, 'asset.png'))->toBe(['width' => 12, 'height' => 8]);
  writePreflightTestPng($path, 9, 21);
  touch($path, 1700000002);
  expect(PngAssetPreflight::inspect($this->root, 'asset.png'))->toBe(['width' => 9, 'height' => 21])
    ->and($this->cache->getValue())->toHaveCount(1);
});

it('retries a previously unavailable asset after it is repaired', function (bool $corrupt) {
  $path = $this->root . '/asset.png';
  if ($corrupt) { file_put_contents($path, 'invalid PNG'); touch($path, 1700000000); }
  expect(PngAssetPreflight::getAvailableSize($this->root, 'asset.png'))->toBeNull()
    ->and($this->cache->getValue()[$path]['error'])->not->toBeNull();
  writePreflightTestPng($path, 17, 11);
  touch($path, 1700000002);
  expect(PngAssetPreflight::getAvailableSize($this->root, 'asset.png'))->toBe(['width' => 17, 'height' => 11])
    ->and($this->cache->getValue()[$path]['error'])->toBeNull();
})->with([false, true]);

it('logs one optional-asset diagnostic per failed revision and reports a later failure again', function () {
  $path = $this->root . '/asset.png';
  $warnings = fn() => file($this->root . '/warning.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  for ($i = 0; $i < 3; $i++) { expect(PngAssetPreflight::getAvailableSize($this->root, 'asset.png'))->toBeNull(); }
  expect($warnings())->toHaveCount(1)->and($warnings()[0])->toContain('asset.png');
  file_put_contents($path, 'invalid PNG');
  touch($path, 1700000000);
  for ($i = 0; $i < 3; $i++) { expect(PngAssetPreflight::getAvailableSize($this->root, 'asset.png'))->toBeNull(); }
  expect($warnings())->toHaveCount(2)->and($warnings()[1])->toContain('Invalid PNG header');
  touch($path, 1700000002);
  expect(PngAssetPreflight::getAvailableSize($this->root, 'asset.png'))->toBeNull();
  expect($warnings())->toHaveCount(3);
  writePreflightTestPng($path, 10, 8);
  touch($path, 1700000004);
  expect(PngAssetPreflight::getAvailableSize($this->root, 'asset.png'))->toBe(['width' => 10, 'height' => 8]);
  unlink($path);
  expect(PngAssetPreflight::getAvailableSize($this->root, 'asset.png'))->toBeNull();
  expect($warnings())->toHaveCount(4);
});

it('checks every crop independently without caching cropped dimensions or poisoning a valid asset', function () {
  writePreflightTestPng($this->root . '/asset.png', 12, 8);
  expect(PngAssetPreflight::inspect($this->root, 'asset.png', new SpriteSourceRect(1, 2, 4, 3)))
    ->toBe(['width' => 4, 'height' => 3]);
  foreach ([new SpriteSourceRect(10, 0, 3, 1), new SpriteSourceRect(0, 7, 1, 2)] as $crop) {
    expect(fn() => PngAssetPreflight::inspect($this->root, 'asset.png', $crop))->toThrow(RuntimeException::class, 'crop exceed');
  }
  expect(PngAssetPreflight::inspect($this->root, 'asset.png', new SpriteSourceRect(6, 4, 6, 4)))
    ->toBe(['width' => 6, 'height' => 4])
    ->and(PngAssetPreflight::getAvailableSize($this->root, 'asset.png'))->toBe(['width' => 12, 'height' => 8])
    ->and($this->cache->getValue()[$this->root . '/asset.png']['size'])->toBe(['width' => 12, 'height' => 8])
    ->and(file_exists($this->root . '/warning.log'))->toBeFalse();
});
