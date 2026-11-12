<?php

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

beforeEach(function () {
  $this->root = \getTestTemporaryDirectory() . '/ichiloto-canvas-budget-' . bin2hex(random_bytes(5));
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
    $texture = CanvasNineSlice::getFromPng($this->root, 'panel.png', 12, 12, 12, 12);
    $images = $texture->images('panel', $bounds, 0);
    CanvasImagePreflight::inspect($images, $this->root);
    expect($texture->source->toArray())->toBe(['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height])
      ->and(array_sum(array_map(fn($image) => $image->destination->width * $image->destination->height, $images)))
      ->toEqualWithDelta($bounds->width * $bounds->height, 0.000001);
  }
  expect(fn() => CanvasNineSlice::getFromPng($this->root, 'panel.png', left: -1))
    ->toThrow(InvalidArgumentException::class);
  expect(fn() => CanvasNineSlice::getFromPng($this->root, 'missing.png'))->toThrow(RuntimeException::class);
});


it('uses negotiated painted-region accounting and restores independent host scopes after failure', function () {
  $images = [];
  foreach (['first.png', 'second.png'] as $name) {
    ($this->png)($name, 4096, 4096);
    $images[] = new CanvasImage($name, $name, new CanvasRectangle(0, 0, 100, 100),
      sourceRect: new SpriteSourceRect(0, 0, 800, 800));
  }
  $new = static fn(string $cap): bool => $cap === \Ichiloto\Engine\Rendering\Transport\RendererSessionConfig::CANVAS_REGION_BUDGET;
  $old = static fn(string $cap): bool => false;
  CanvasImagePreflight::runForSession($new, function () use ($images, $old): void {
    $budget = CanvasImagePreflight::inspect($images, $this->root);
    expect(array_sum($budget['sources']))->toBe(134217728)
      ->and(array_sum($budget['regions']))->toBe(2 * 804 * 804 * 4);
    expect(fn() => CanvasImagePreflight::runForSession($old,
      fn() => CanvasImagePreflight::inspect($images, $this->root)))
      ->toThrow(\Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageBudgetExceeded::class, 'legacy whole-PNG');
    expect(CanvasImagePreflight::inspect($images, $this->root))->toBe($budget);
  });
  expect(fn() => CanvasImagePreflight::inspect($images, $this->root))
    ->toThrow(RuntimeException::class, 'PNG sources');
});

it('counts the guarded original and distinct tone and flip variants while deduplicating aliases', function () {
  ($this->png)('sheet.png', 4000, 4000);
  symlink($this->root . '/sheet.png', $this->root . '/alias.png');
  $images = [];
  foreach ([[1.0, false, false], [0.5, false, false], [0.5, true, false], [0.0, true, true], [-0.0, true, true]] as $i => [$tone, $x, $y]) {
    $images[] = new CanvasImage('image-' . $i, $i % 2 ? 'alias.png' : 'sheet.png',
      new CanvasRectangle(0, 0, 10, 10), sourceRect: new SpriteSourceRect(10, 10, 500, 500),
      brightness: $tone, flipX: $x, flipY: $y);
  }
  $budget = CanvasImagePreflight::runForSession(static fn(string $cap): bool => true,
    fn() => CanvasImagePreflight::inspect($images, $this->root));
  expect($budget['sources'])->toHaveCount(1)->and($budget['regions'])->toHaveCount(4)
    ->and(array_sum($budget['regions']))->toBe(4 * 504 * 504 * 4);
});

it('keeps whole composite sources bounded under region accounting and reports measured costs', function () {
  $operations = [];
  foreach (['a.png', 'b.png'] as $name) {
    ($this->png)($name, 4096, 4096);
    $operations[] = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation([
      'type' => 'image', 'asset' => $name,
      'source' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
      'destination' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]]);
  }
  $composite = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite('image', 1, 1,
    new CanvasRectangle(0, 0, 1, 1), $operations);
  expect(fn() => CanvasImagePreflight::runForSession(static fn(string $cap): bool => true,
    fn() => CanvasImagePreflight::inspect([], $this->root, [$composite])))
    ->toThrow(RuntimeException::class, '134217728/67108864 bytes (whole-source consumers)');
});

it('keeps an isolated frame composer on its own negotiated session', function () {
  ($this->png)('a.png', 4096, 4096);
  ($this->png)('b.png', 4096, 4096);
  $source = new class($this->root) implements
    \Ichiloto\Engine\Rendering\Presentation\FramePresentationSourceInterface,
    \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface {
    public function __construct(private string $root) {}
    public function getPresentationSprites(): array { return []; }
    public function getPresentationCanvas(): ?\Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas {
      $images = array_map(static fn(string $name) => new CanvasImage($name, $name,
        new CanvasRectangle(0, 0, 100, 100), sourceRect: new SpriteSourceRect(0, 0, 100, 100)), ['a.png', 'b.png']);
      CanvasImagePreflight::inspect($images, $this->root);
      return new \Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas(1350, 720, $images);
    }
  };
  $composer = new \Ichiloto\Engine\Rendering\Presentation\SceneFrameComposer($this->root);
  $compose = fn(Closure $supports) => $composer->composeFrame($source,
    new \Ichiloto\Engine\Rendering\Transport\RendererGridConfig(), $supports,
    static fn() => throw new LogicException('Exclusive canvas does not collect terminal text.'));
  CanvasImagePreflight::runForSession(static fn(string $cap): bool => true, function () use ($compose): void {
    expect(fn() => $compose(static fn(string $cap): bool => false))->toThrow(RuntimeException::class, 'legacy whole-PNG');
    expect($compose(static fn(string $cap): bool => true)->canvas->images)->toHaveCount(2);
  });
});


it('reports measured budget fallback defects once as errors and keeps ordinary fallback warnings', function () {
  $debug = \Ichiloto\Engine\Util\Debug::class;
  $statics = new ReflectionClass($debug)->getStaticProperties();
  try {
    $debug::configure(['log_directory' => $this->root]);
    $error = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageBudgetExceeded(
      ['large.png' => 70000000], ['large.png' => 70000000], ['large.png:crop' => 4], false);
    $report = new \Ichiloto\Engine\Rendering\Presentation\PresentationFallbackReport();
    $message = 'Results: ' . $error->getMessage();
    $wrapped = new RuntimeException('Transition: ' . $message, previous: $error);
    $report->report($message, $wrapped);
    $report->report($message, $wrapped);
    $composite = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeBudgetExceeded('operations 360 > 256');
    $report->report($composite->getMessage(), new RuntimeException('Wrapped', previous: $composite));
    $report->report($composite->getMessage(), $composite);
    $report->report('Missing optional artwork.');
    expect(file_get_contents($this->root . '/error.log'))->toContain('70000000/67108864', 'large.png=70000000 bytes')
      ->and(substr_count(file_get_contents($this->root . '/error.log'), '[ERROR]'))->toBe(2)
      ->and(file_get_contents($this->root . '/error.log'))->toContain('operations 360 > 256')
      ->and(file_get_contents($this->root . '/warning.log'))->toContain('Missing optional artwork.')
      ->not->toContain('70000000');
  } finally {
    foreach ($statics as $name => $value) { new ReflectionProperty($debug, $name)->setValue(null, $value); }
  }
});
