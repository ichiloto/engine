<?php

use Composer\Autoload\ClassLoader;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetBounds;

it('preserves crop ordering, transforms, diagnostics and lower edges across a mixed batch', function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
  $root = createTestDirectory('png-bounds-batch');
  $sheet = imagecreatetruecolor(24, 12);
  imagealphablending($sheet, false); imagesavealpha($sheet, true);
  imagefill($sheet, 0, 0, imagecolorallocatealpha($sheet, 0, 0, 0, 127));
  imagefilledrectangle($sheet, 2, 3, 7, 9, imagecolorallocatealpha($sheet, 80, 120, 200, 0));
  imagefilledrectangle($sheet, 14, 2, 20, 10, imagecolorallocatealpha($sheet, 80, 120, 200, 126));
  imagepng($sheet, $root . '/sheet.png');
  imagepng($sheet, $root . '/other.png');
  $images = [
    new CanvasImage('faint', 'sheet.png', new CanvasRectangle(0, 0, 120, 120), sourceRect: new SpriteSourceRect(12, 0, 12, 12)),
    new CanvasImage('other', 'other.png', new CanvasRectangle(10, 20, 120, 120), sourceRect: new SpriteSourceRect(0, 0, 12, 12)),
    new CanvasImage('turned', 'sheet.png', new CanvasRectangle(0, 0, 120, 120), sourceRect: new SpriteSourceRect(0, 0, 12, 12),
      flipX: true, rotation: 90, pivot: new PresentationSpritePivot(.2, .4), clipRect: new CanvasRectangle(0, 0, 100, 100)),
    new CanvasImage('missing', 'missing.png', new CanvasRectangle(0, 0, 120, 120)),
    new CanvasImage('bad-crop', 'sheet.png', new CanvasRectangle(0, 0, 120, 120), sourceRect: new SpriteSourceRect(20, 0, 12, 12)),
  ];
  $actual = PngAssetBounds::getVisibleBoundsForImages($root, $images);
  expect($actual)->toHaveCount(5)->and($actual[0]['bounds'])->toEqual(new CanvasRectangle(20, 20, 70, 90))
    ->and($actual[1]['bounds'])->toEqual(new CanvasRectangle(30, 50, 60, 70))
    ->and($actual[3]['diagnostic'])->toContain('readable PNG')->and($actual[4]['diagnostic'])->toContain('exceed image bounds');
  foreach ($images as $i => $image) { expect($actual[$i])->toEqual(PngAssetBounds::getVisibleBounds($root, $image)); }
  expect(PngAssetBounds::getGroundContact($root, $images[0], .28))->toBeNull()
    ->and(PngAssetBounds::getGroundContact($root, $images[1], .28)['bottom'])->toBe(10 / 12)
    ->and(PngAssetBounds::getSurfaceSpan($root, $images[1], 120))->toEqual([30.0, 90.0])
    ->and(PngAssetBounds::getVisibleBoundsForImages($root, []))->toBe([]);
});

it('decodes each missing sheet once, releases it before the next, and rereads replaced content', function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
  $root = createTestDirectory('png-batch-decoding');
  $autoload = dirname((new ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
  $source = dirname(__DIR__, 2) . '/src';
  $program = <<<'CODE'
namespace Ichiloto\Engine\Rendering\Sprites {
  function hash_file(string $algorithm, string $path): string|false {
    $GLOBALS['revisionReads']++;
    return \hash_file($algorithm, $path);
  }
  function imagecreatefromstring(string $bytes): \GdImage|false {
    $GLOBALS['decodeCount']++;
    $live = count(array_filter($GLOBALS['decodedRefs'], static fn($ref) => $ref->get() !== null));
    $GLOBALS['maxOtherLive'] = max($GLOBALS['maxOtherLive'], $live);
    $image = \imagecreatefromstring($bytes);
    if ($image !== false) { $GLOBALS['decodedRefs'][] = \WeakReference::create($image); }
    return $image;
  }
}
namespace {
  $loader = require AUTOLOAD;
  $loader->setPsr4('Ichiloto\\Engine\\', SOURCE);
  $root = ROOT;
  \Ichiloto\Engine\Rendering\Sprites\PngAssetBounds::setFactsCache(
    new \Ichiloto\Engine\Rendering\Sprites\PngAssetFactsCache($root . '/generated'));

  $GLOBALS['revisionReads'] = $GLOBALS['decodeCount'] = $GLOBALS['maxOtherLive'] = 0; $GLOBALS['decodedRefs'] = [];
  $write = static function (string $path, int $inset): void {
    $sheet = imagecreate(96, 16);
    $clear = imagecolorallocate($sheet, 0, 0, 0); imagecolortransparent($sheet, $clear);
    $color = imagecolorallocate($sheet, 50, 100, 200);
    for ($x = 0; $x < 96; $x += 16) { imagefilledrectangle($sheet, $x + $inset, 2, $x + 12, 13, $color); }
    imagepng($sheet, $path);
  };
  $write($root . '/a.png', 2); $write($root . '/b.png', 3);
  $images = [];
  for ($i = 0; $i < 6; $i++) {
    foreach (['a.png', 'b.png'] as $asset) {
      $images[] = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage('cell', $asset,
        new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle(0, 0, 160, 160),
        sourceRect: new \Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect($i * 16, 0, 16, 16));
    }
  }
  $first = \Ichiloto\Engine\Rendering\Sprites\PngAssetBounds::getVisibleBoundsForImages($root, $images);
  $counts[] = $GLOBALS['decodeCount']; $revisions[] = $GLOBALS['revisionReads'];
  \Ichiloto\Engine\Rendering\Sprites\PngAssetBounds::getVisibleBoundsForImages($root, $images);
  $counts[] = $GLOBALS['decodeCount']; $revisions[] = $GLOBALS['revisionReads'];
  $write($root . '/a.png', 6);
  $changed = \Ichiloto\Engine\Rendering\Sprites\PngAssetBounds::getVisibleBoundsForImages($root, $images);
  $counts[] = $GLOBALS['decodeCount']; $revisions[] = $GLOBALS['revisionReads'];
  echo json_encode(['counts' => $counts, 'revisionReads' => $revisions, 'otherLiveAtDecode' => $GLOBALS['maxOtherLive'],
    'liveAfter' => count(array_filter($GLOBALS['decodedRefs'], static fn($ref) => $ref->get() !== null)),
    'beforeX' => $first[0]['bounds']->x, 'afterX' => $changed[0]['bounds']->x,
    'otherUnchanged' => $first[1] == $changed[1]]);
}
CODE;
  $program = str_replace(['AUTOLOAD', 'SOURCE', 'ROOT'], array_map(var_export(...), [$autoload, $source, $root], [true, true, true]), $program);
  exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=128M -d max_execution_time=10 -r ' . escapeshellarg($program), $output, $exit);
  expect($exit)->toBe(0);
  $facts = json_decode(implode('', $output), true, flags: JSON_THROW_ON_ERROR);
  expect($facts)->toBe(['counts' => [2, 2, 3], 'revisionReads' => [2, 4, 6], 'otherLiveAtDecode' => 0, 'liveAfter' => 0,
    'beforeX' => 20, 'afterX' => 60, 'otherUnchanged' => true]);
});

it('keeps batch decode failures and absent optional GD explicit', function (bool $withoutDecoder) {
  require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';
  $root = createTestDirectory('png-batch-unavailable');
  \Tests\Support\Rendering\writeTestPng($root . '/good.png', 10, 10);
  file_put_contents($root . '/broken.png', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', 10, 10));
  $autoload = dirname((new ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
  $source = dirname(__DIR__, 2) . '/src';
  $code = '$loader = require ' . var_export($autoload, true) . '; $loader->setPsr4("Ichiloto\\\\Engine\\\\", '
    . var_export($source, true) . '); $images = []; foreach (["good.png", "broken.png"] as $asset) {'
    . '$images[] = new \\Ichiloto\\Engine\\Rendering\\Presentation\\Canvas\\CanvasImage("image", $asset, '
    . 'new \\Ichiloto\\Engine\\Rendering\\Presentation\\Canvas\\CanvasRectangle(0, 0, 10, 10)); }'
    . '\\Ichiloto\\Engine\\Rendering\\Sprites\\PngAssetBounds::setFactsCache(new \\Ichiloto\\Engine\\Rendering\\Sprites\\PngAssetFactsCache(' . var_export($root . '/generated', true) . '));'
    . 'echo json_encode(\\Ichiloto\\Engine\\Rendering\\Sprites\\PngAssetBounds::getVisibleBoundsForImages('
    . var_export($root, true) . ', $images));';
  exec(escapeshellarg(PHP_BINARY) . ($withoutDecoder ? ' -n -d disable_functions=imagecreatefrompng' : '')
    . ' -d memory_limit=128M -d max_execution_time=10 -r ' . escapeshellarg($code), $output, $exit);
  expect($exit)->toBe(0);
  $actual = json_decode(implode('', $output), true, flags: JSON_THROW_ON_ERROR);
  expect($actual[1]['bounds'])->toBeNull()->and($actual[1]['diagnostic'])
    ->toContain($withoutDecoder ? 'optional GD PNG support is not installed' : 'could not decode');
  if (!$withoutDecoder) { expect($actual[0]['diagnostic'])->toBeNull(); }
})->with([false, true]);

it('attributes cold PNG work and revision checks while keeping warm facts unchanged', function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
  $root = createTestDirectory('png-batch-trace');
  $sheet = imagecreatetruecolor(8, 4);
  imagealphablending($sheet, false); imagesavealpha($sheet, true);
  imagefill($sheet, 0, 0, imagecolorallocatealpha($sheet, 0, 0, 0, 127));
  imagefilledrectangle($sheet, 2, 1, 6, 2, imagecolorallocatealpha($sheet, 10, 20, 30, 0));
  imagepng($sheet, $root . '/sheet.png');
  $images = array_map(static fn(int $x): CanvasImage => new CanvasImage('cell', 'sheet.png',
    new CanvasRectangle(0, 0, 4, 4), sourceRect: new SpriteSourceRect($x, 0, 4, 4)), [0, 4]);
  $records = [];
  \Ichiloto\Engine\Diagnostics\LatencyTrace::configure(function (array $r) use (&$records): void { $records[] = $r; });
  try {
    $cold = PngAssetBounds::getVisibleBoundsForImages($root, $images);
    $stages = array_count_values(array_column($records, 'stage'));
    expect($stages['png.content_revision'])->toBe(1)->and($stages['png.bounds.decode'])->toBe(1)
      ->and($stages['png.bounds.scan'])->toBe(2);
    foreach ($records as $record) {
      expect($record['asset'])->toBe('sheet.png')->and($record)
        ->toHaveKeys(['duration_ns', 'cpu_user_ns', 'cpu_system_ns']);
    }
    $records = [];
    expect(PngAssetBounds::getVisibleBoundsForImages($root, $images))->toEqual($cold)
      ->and(array_column($records, 'stage'))->toBe(['png.content_revision']);
  } finally { \Ichiloto\Engine\Diagnostics\LatencyTrace::configure(); }
});

it('never caches replacement pixels for the revision observed before a sheet changed', function (bool $batch) {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
  $root = createTestDirectory('png-batch-revision-replacement');
  $path = $root . '/sheet.png';
  $write = static function (string $file, int $inset): void {
    $sheet = imagecreatetruecolor(16, 8);
    imagealphablending($sheet, false);
    imagesavealpha($sheet, true);
    imagefill($sheet, 0, 0, 0x7f000000);
    foreach ([0, 8] as $offset) {
      imagesetpixel($sheet, $offset + $inset, 3, 0x00558899);
    }
    imagepng($sheet, $file);
  };
  $write($path, 2);
  $write($root . '/replacement.png', 5);
  $original = file_get_contents($path);
  $store = new \Ichiloto\Engine\Rendering\Sprites\PngAssetFactsCache($root . '/generated');
  PngAssetBounds::setFactsCache($store);
  $images = array_map(static fn(int $x): CanvasImage => new CanvasImage('cell', 'sheet.png',
    new CanvasRectangle(0, 0, 8, 8), sourceRect: new SpriteSourceRect($x, 0, 8, 8)), [0, 8]);
  $replaced = false;
  \Ichiloto\Engine\Diagnostics\LatencyTrace::configure(static function (array $record) use ($root, $path, &$replaced): void {
    if ($record['stage'] === 'png.content_revision' && !$replaced) {
      $replaced = rename($root . '/replacement.png', $path);
    }
  });
  try {
    $interrupted = $batch ? PngAssetBounds::getVisibleBoundsForImages($root, $images)
      : [PngAssetBounds::getVisibleBounds($root, $images[0])];
  } finally {
    \Ichiloto\Engine\Diagnostics\LatencyTrace::configure();
  }
  expect($replaced)->toBeTrue();
  foreach ($interrupted as $result) {
    expect($result['bounds'])->toBeNull()->and($result['diagnostic'])->toContain('changed during inspection');
  }
  expect(glob($root . '/generated/*.json'))->toBe([]);
  $current = PngAssetBounds::getVisibleBoundsForImages($root, $images);
  expect(array_column(array_column($current, 'bounds'), 'x'))->toBe([5.0, 5.0]);
  file_put_contents($path, $original);
  $restored = PngAssetBounds::getVisibleBoundsForImages($root, $images);
  expect(array_column(array_column($restored, 'bounds'), 'x'))->toBe([2.0, 2.0]);
})->with(['batch' => true, 'single' => false]);

it('requires prepared facts for the current source revision before handing over a scene', function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
  $root = createTestDirectory('sprite-facts-scene-readiness');
  $sheetPath = $root . '/entry.png';
  $write = static function (string $file, int $pixelX): void {
    $image = imagecreatetruecolor(8, 4);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, 0x7f000000);
    imagesetpixel($image, $pixelX, 2, 0x00558899);
    imagepng($image, $file);
  };
  $store = new \Ichiloto\Engine\Rendering\Sprites\PngAssetFactsCache($root . '/generated');
  PngAssetBounds::setFactsCache($store);
  $plan = new \Ichiloto\Engine\Battle\Presentation\BattleArtworkFacts($root);
  $plan->addArtwork(new \Ichiloto\Engine\Battle\Presentation\BattlerArtwork('entry.png', 8, 4, 4, 4));
  $write($sheetPath, 1);
  expect($plan->hasCurrentFacts())->toBeFalse();
  $plan->prepare();
  PngAssetBounds::setFactsCache($store);
  expect($plan->hasCurrentFacts())->toBeTrue();
  $facts = glob($root . '/generated/*.json');
  expect($facts)->toHaveCount(1);
  $firstFacts = hash_file('sha256', $facts[0]);
  $write($root . '/replacement.png', 5);
  rename($root . '/replacement.png', $sheetPath);
  $stages = [];
  \Ichiloto\Engine\Diagnostics\LatencyTrace::configure(static function (array $record) use (&$stages): void {
    $stages[] = $record['stage'];
  });
  try {
    expect($plan->hasCurrentFacts())->toBeFalse()->and(hash_file('sha256', $facts[0]))->toBe($firstFacts);
    expect($stages)->toBe(['png.content_revision']);
    $plan->prepare();
    PngAssetBounds::setFactsCache($store);
    $stages = [];
    \Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight::runWithSnapshot(function () use ($plan, $root): void {
      expect($plan->hasCurrentFacts())->toBeTrue();
      $visible = PngAssetBounds::getVisibleBoundsForImages($root, $plan->getImages());
      expect($visible[0]['bounds'])->toEqual(new CanvasRectangle(5, 2, 1, 1));
    });
    expect($stages)->toBe(['png.content_revision']);
  } finally {
    \Ichiloto\Engine\Diagnostics\LatencyTrace::configure();
  }
});


it('imports generated records in bounded steps and rejects a replaced source before consuming them', function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
  $root = createTestDirectory('sprite-facts-hydration');
  $draw = static function (string $name, int $pixelX) use ($root): void {
    $image = imagecreatetruecolor(8, 4);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, 0x7f000000);
    imagesetpixel($image, $pixelX, 2, 0x00558899);
    imagepng($image, $root . '/' . $name);
  };
  $draw('first.png', 1);
  $draw('second.png', 2);
  $store = new \Ichiloto\Engine\Rendering\Sprites\PngAssetFactsCache($root . '/generated');
  PngAssetBounds::setFactsCache($store);
  $images = array_map(static fn(string $file): CanvasImage => new CanvasImage('crop', $file,
    new CanvasRectangle(0, 0, 8, 4)), ['first.png', 'second.png']);
  PngAssetBounds::getVisibleBoundsForImages($root, $images);
  $files = glob($root . '/generated/*.json');
  $hashes = array_map(static fn(string $file): string => hash_file('sha256', $file), $files);
  PngAssetBounds::setFactsCache($store);
  $stages = [];
  \Ichiloto\Engine\Diagnostics\LatencyTrace::configure(static function (array $record) use (&$stages): void {
    $stages[] = $record['stage'];
  });
  try {
    expect(PngAssetBounds::hasCurrentFacts($root, $images, memoryOnly: true))->toBeFalse();
    $next = PngAssetBounds::hydrateCurrentFacts($root, $images, 0, 0);
    expect($next)->toBe(1)->and(PngAssetBounds::hasCurrentFacts($root, $images, memoryOnly: true))->toBeFalse();
    $next = PngAssetBounds::hydrateCurrentFacts($root, $images, $next, 0);
    expect($next)->toBe(2)->and(PngAssetBounds::hasCurrentFacts($root, $images, memoryOnly: true))->toBeTrue();

    $draw('replacement.png', 5);
    rename($root . '/replacement.png', $root . '/first.png');
    expect(PngAssetBounds::hasCurrentFacts($root, $images, memoryOnly: true))->toBeFalse()
      ->and(PngAssetBounds::hydrateCurrentFacts($root, $images, 0, PHP_INT_MAX))->toBeNull()
      ->and(array_map(static fn(string $file): string => hash_file('sha256', $file), $files))->toBe($hashes)
      ->and($stages)->not->toContain('png.bounds.decode', 'png.bounds.scan');
  } finally {
    \Ichiloto\Engine\Diagnostics\LatencyTrace::configure();
  }
});


it('invalidates a prepared presentation when a source image changes without resizing', function () {
  if (!function_exists('imagecreatefrompng')) { $this->markTestSkipped('Optional GD PNG inspection is unavailable.'); }
  $root = createTestDirectory('sprite-presentation-versions');
  $image = imagecreatetruecolor(8, 4);
  imagepng($image, $root . '/base.png');
  imagesetpixel($image, 3, 2, 0x00558899);
  imagepng($image, $root . '/next.png');
  $preflight = \Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight::class;
  [$identity, $versions] = $preflight::captureReadVersions($root, static fn(): array => $preflight::getContentIdentity($root, 'base.png'));
  expect($versions)->toHaveKey('base.png')->and($versions['base.png']['contentRevision'])->toBe($identity['revision'])
    ->and($preflight::hasReadVersions($root, $versions))->toBeTrue();
  rename($root . '/next.png', $root . '/base.png');
  expect($preflight::inspect($root, 'base.png'))->toBe(['width' => 8, 'height' => 4])
    ->and($preflight::hasReadVersions($root, $versions))->toBeFalse();
});
