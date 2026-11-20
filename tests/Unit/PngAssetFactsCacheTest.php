<?php

use Composer\Autoload\ClassLoader;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Sprites\PngAssetBounds;
use Ichiloto\Engine\Rendering\Sprites\PngAssetFactsCache;

it('reuses exact imported crop facts in a fresh process without a decoder and regenerates changed or corrupt entries', function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
  $root = createTestDirectory('persisted-png-facts'); $cache = $root . '/generated';
  $write = static function (int $inset) use ($root): void {
    $sheet = imagecreatetruecolor(24, 12);
    imagealphablending($sheet, false); imagesavealpha($sheet, true);
    imagefill($sheet, 0, 0, imagecolorallocatealpha($sheet, 0, 0, 0, 127));
    $solid = imagecolorallocatealpha($sheet, 80, 120, 200, 0);
    imagefilledrectangle($sheet, $inset, 3, 8, 9, $solid);
    imagefilledrectangle($sheet, 14, 2, 20, 10, imagecolorallocatealpha($sheet, 80, 120, 200, 126));
    imagepng($sheet, $root . '/sheet.png');
  };
  $write(2);
  $autoload = dirname((new ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
  $source = dirname(__DIR__, 2) . '/src';
  $code = <<<'CODE'
namespace Ichiloto\Engine\Rendering\Sprites {
  function imagecreatefromstring(string $bytes): \GdImage|false { $GLOBALS['decodes']++; return \imagecreatefromstring($bytes); }
}
namespace {
  $loader = require AUTOLOAD;
  $loader->setPsr4('Ichiloto\\Engine\\', SOURCE);
  $GLOBALS['decodes'] = 0;
  \Ichiloto\Engine\Rendering\Sprites\PngAssetBounds::setFactsCache(new \Ichiloto\Engine\Rendering\Sprites\PngAssetFactsCache(CACHE));
  $images = [];
  foreach ([0, 12] as $x) {
    $images[] = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage('cell', 'sheet.png',
      new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle(10, 20, 120, 120),
      sourceRect: new \Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect($x, 0, 12, 12), flipX: true);
  }
  $bounds = \Ichiloto\Engine\Rendering\Sprites\PngAssetBounds::getVisibleBoundsForImages(ROOT, $images);
  $contact = \Ichiloto\Engine\Rendering\Sprites\PngAssetBounds::getGroundContact(ROOT, $images[0], .28);
  $surface = \Ichiloto\Engine\Rendering\Sprites\PngAssetBounds::getSurfaceSpan(ROOT, $images[0], 110);
  echo json_encode(['bounds' => $bounds, 'contact' => $contact, 'surface' => $surface, 'decodes' => $GLOBALS['decodes']]);
}
CODE;
  $code = str_replace(['AUTOLOAD', 'SOURCE', 'CACHE', 'ROOT'],
    array_map(static fn(string $value): string => var_export($value, true), [$autoload, $source, $cache, $root]), $code);
  $run = static function (bool $withoutDecoder = false) use ($code): array {
    exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=128M -d max_execution_time=10'
      . ($withoutDecoder ? ' -n -d disable_functions=imagecreatefrompng' : '') . ' -r ' . escapeshellarg($code), $lines, $exit);
    expect($exit)->toBe(0);
    return json_decode(implode('', $lines), true, flags: JSON_THROW_ON_ERROR);
  };
  $cold = $run(); $warm = $run(true);
  expect($cold['decodes'])->toBe(1)->and($warm['decodes'])->toBe(0);
  unset($cold['decodes'], $warm['decodes']); expect($warm)->toBe($cold);
  $files = glob($cache . '/*.json'); expect($files)->toHaveCount(2);
  foreach ($files as $file) { file_put_contents($file, '{"schema":1,"pixels":"corrupt"}'); }
  $repaired = $run(); expect($repaired['decodes'])->toBe(1); unset($repaired['decodes']); expect($repaired)->toBe($cold);
  $time = filemtime($root . '/sheet.png'); $before = file_get_contents($root . '/sheet.png');
  $write(5); $after = file_get_contents($root . '/sheet.png'); $length = max(strlen($before), strlen($after)) + 32;
  file_put_contents($root . '/sheet.png', str_pad($before, $length, "\0")); touch($root . '/sheet.png', $time); $run();
  file_put_contents($root . '/sheet.png', str_pad($after, $length, "\0")); touch($root . '/sheet.png', $time);
  $changed = $run();
  expect($changed['decodes'])->toBe(1)->and($changed['bounds'][0]['bounds'])->not->toBe($cold['bounds'][0]['bounds'])
    ->and($changed['bounds'][1])->toBe($cold['bounds'][1])->and(glob($cache . '/*.json'))->toHaveCount(2);
});

it('rejects malformed and oversized lower edges and never follows a cache symlink', function () {
  $root = createTestDirectory('png-facts-validation'); $cache = new PngAssetFactsCache($root . '/generated');
  $facts = ['version' => 'revision', 'pixels' => [1, 2, 3, 4], 'edge' => [1 => 5, 3 => 4]];
  $cache->store('/synthetic/source.png', 'revision', null, 10, 10, $facts);
  expect($cache->find('/synthetic/source.png', 'revision', null, 10, 10))->toBe($facts);
  $file = glob($root . '/generated/*.json')[0]; $valid = file_get_contents($file);
  $bad = json_decode($valid, true, flags: JSON_THROW_ON_ERROR); $bad['edge'] = [[3, 4], [1, 5]];
  file_put_contents($file, json_encode($bad)); expect($cache->find('/synthetic/source.png', 'revision', null, 10, 10))->toBeNull();
  file_put_contents($file, str_repeat(' ', 128 * 1024 + 1)); expect($cache->find('/synthetic/source.png', 'revision', null, 10, 10))->toBeNull();
  unlink($file); file_put_contents($root . '/foreign.txt', 'preserve'); symlink($root . '/foreign.txt', $file);
  $cache->store('/synthetic/source.png', 'revision', null, 10, 10, $facts);
  expect(file_get_contents($root . '/foreign.txt'))->toBe('preserve')->and(is_link($file))->toBeTrue();
});

it('keeps exact drawing results when persistence is unavailable', function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
  $root = createTestDirectory('png-facts-readonly'); file_put_contents($root . '/blocked', 'preserve');
  $sheet = imagecreatetruecolor(4, 4); imagepng($sheet, $root . '/image.png');
  PngAssetBounds::setFactsCache(new PngAssetFactsCache($root . '/blocked/generated'));
  $result = PngAssetBounds::getVisibleBounds($root, new CanvasImage('still', 'image.png', new CanvasRectangle(2, 3, 8, 8)));
  expect($result['diagnostic'])->toBeNull()->and($result['bounds'])->toEqual(new CanvasRectangle(2, 3, 8, 8))
    ->and(file_get_contents($root . '/blocked'))->toBe('preserve');
});

it('bounds owned generated record count and preserves unrelated cache-directory files', function () {
  $root = createTestDirectory('png-facts-owned-budget');
  file_put_contents($root . '/keep.txt', 'foreign work');
  for ($i = 0; $i < 2049; $i++) {
    $file = $root . '/' . hash('sha256', 'owned-cache-record-' . $i) . '.json';
    file_put_contents($file, '{}'); touch($file, 1000 + $i);
  }
  $cache = new PngAssetFactsCache($root);
  $facts = ['version' => 'current', 'pixels' => [0, 0, 1, 1], 'edge' => [0 => 0]];
  $cache->store('/synthetic/current.png', 'current', null, 1, 1, $facts);
  expect(glob($root . '/*.json'))->toHaveCount(2048)->and(file_get_contents($root . '/keep.txt'))->toBe('foreign work')
    ->and($cache->find('/synthetic/current.png', 'current', null, 1, 1))->toBe($facts);
});

it('maintains the shared cache budget through nested batches and interrupted work', function () {
  $root = createTestDirectory('png-facts-batch-budget');
  file_put_contents($root . '/keep.txt', 'foreign work');
  for ($i = 0; $i < 2048; $i++) {
    $file = $root . '/' . hash('sha256', 'owned-batch-record-' . $i) . '.json';
    file_put_contents($file, '{}');
    touch($file, 1000 + $i);
  }
  $cache = new PngAssetFactsCache($root);
  $facts = ['version' => 'current', 'pixels' => [0, 0, 1, 1], 'edge' => [0 => 0]];
  $write = static function (int $index) use ($cache, $facts): void {
    $cache->store('/synthetic/' . $index . '.png', 'current', null, 1, 1, $facts);
  };

  expect(fn() => $cache->runBatch(function () use ($cache, $write, $root): never {
    $cache->runBatch(static function () use ($write): void {
      for ($i = 0; $i < 16; $i++) { $write($i); }
    });
    expect(glob($root . '/*.json'))->toHaveCount(2048);
    $write(16);
    throw new RuntimeException('stop preparation');
  }))->toThrow(RuntimeException::class, 'stop preparation');

  expect(glob($root . '/*.json'))->toHaveCount(2048)
    ->and($cache->find('/synthetic/16.png', 'current', null, 1, 1))->toBe($facts)
    ->and(file_get_contents($root . '/keep.txt'))->toBe('foreign work');
  $write(17);
  expect(glob($root . '/*.json'))->toHaveCount(2048)
    ->and($cache->find('/synthetic/17.png', 'current', null, 1, 1))->toBe($facts);
});
