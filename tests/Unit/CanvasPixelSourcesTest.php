<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasComposite, CanvasCompositeMask, CanvasCompositeOperation, CanvasImage, CanvasImagePreflight, CanvasImageTint, CanvasRectangle, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\{PresentationColor, PresentationSession, RendererPresentation, SpriteSourceRect};
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\{RendererEvent, RendererGridConfig, RendererSessionConfig};
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->root = createTestDirectory('ichiloto-pixel-sources-');
  $this->debug = new ReflectionClass(Debug::class)->getStaticProperties();
  Debug::configure(['log_directory' => $this->root . '/logs']);
  writeTestPng($this->root . '/source.png', 11, 7);
  $this->rect = ['x' => 2, 'y' => 2, 'width' => 7, 'height' => 3];
  $this->destination = ['x' => 0, 'y' => 0, 'width' => 70, 'height' => 30];
  $this->image = ['type' => 'image', 'asset' => 'source.png', 'destination' => $this->destination];
  $this->mask = ['type' => 'image_alpha', 'asset' => 'source.png', 'destination' => $this->destination];
  $this->makePresenter = function (bool $pixels): RendererPresentation {
    $caps = ['graphical_canvas', 'canvas_compositing', ...($pixels ? ['canvas_source_pixels'] : [])];
    $transport = new FakeRendererTransport(); $client = new RendererClient($transport);
    $client->start(new RendererSessionConfig('Synthetic pixels', $this->root, protocol: RendererProtocolVersion::V2));
    $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => $caps]))];
    $client->pump();
    return new RendererPresentation($client, new RendererGridConfig(1, 1));
  };
  $this->makeCanvas = function (array $data): PresentationCanvas {
    return new PresentationCanvas(100, 100, composites: [new CanvasComposite('sample', 70, 30,
      new CanvasRectangle(0, 0, 70, 30), [new CanvasCompositeOperation($data)])]);
  };
});

afterEach(function () {
  foreach ($this->debug as $name => $value) { new ReflectionProperty(Debug::class, $name)->setValue(null, $value); }
});

it('preserves exact integer crop coordinates in the shared odd-atlas fixture for image and mask samplers', function () {
  $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/Renderer/pixel-sources-wire-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
  expect($fixture['capability'])->toBe(RendererSessionConfig::CANVAS_SOURCE_PIXELS);
  foreach ($fixture['cases'] as $case) {
    foreach ($case['samplers'] as $type) {
      $data = ['type' => $type, 'asset' => 'source.png', 'destination' => $case['destination'],
        'sourcePixels' => $case['sourcePixels'], ...array_intersect_key($case, array_flip(['flipX', 'flipY']))];
      $parsed = $type === 'image' ? new CanvasCompositeOperation($data)->data : new CanvasCompositeMask($data)->data;
      expect($parsed['sourcePixels'])->toEqual($case['sourcePixels'])->and($parsed)->not->toHaveKey('source');
    }
  }
  $image = new CanvasImage('crop', 'source.png', new CanvasRectangle(0, 0, 70, 30), sourceRect: new SpriteSourceRect(2, 2, 7, 3));
  $tint = PresentationSession::runForSession(static fn($cap): bool => $cap === RendererSessionConfig::CANVAS_SOURCE_PIXELS,
    fn() => CanvasImageTint::compose('exact', $image, PresentationColor::ansi16(1), .2, $this->root));
  $mask = $tint->operations[0]->data['masks'][0];
  expect($mask['sourcePixels'])->toBe(['x' => 2.0, 'y' => 2.0, 'width' => 7.0, 'height' => 3.0])
    ->and($mask['sourcePixels']['x'] + $mask['sourcePixels']['width'])->toBe(9.0)
    ->and($mask)->not->toHaveKey('source');
});

it('keeps clipped pixel geometry and mirror flags in the selected cell', function (bool $flipX, bool $flipY) {
  $image = new CanvasImage('clipped', 'source.png', new CanvasRectangle(0, 0, 70, 30),
    sourceRect: new SpriteSourceRect(2, 2, 7, 3), clipRect: new CanvasRectangle(10, 5, 30, 15),
    flipX: $flipX, flipY: $flipY, rotation: 0,
    pivot: new \Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot(.5, .5));
  $tint = PresentationSession::runForSession(static fn($cap): bool => $cap === RendererSessionConfig::CANVAS_SOURCE_PIXELS,
    fn() => CanvasImageTint::compose('clipped', $image, PresentationColor::ansi16(1), .2, $this->root));
  $mask = $tint->operations[0]->data['masks'][0];
  expect($mask['sourcePixels']['x'])->toEqualWithDelta($flipX ? 5 : 3, 1e-14)
    ->and($mask['sourcePixels']['y'])->toEqualWithDelta($flipY ? 3 : 2.5, 1e-14)
    ->and($mask['sourcePixels']['width'])->toEqualWithDelta(3, 1e-14)
    ->and($mask['sourcePixels']['height'])->toEqualWithDelta(1.5, 1e-14)
    ->and($mask['flipX'] ?? false)->toBe($flipX)->and($mask['flipY'] ?? false)->toBe($flipY)
    ->and(CanvasImagePreflight::inspect([], $this->root, [$tint])['sources'])->toHaveCount(1);
})->with([false, true])->with([false, true]);

it('rejects ambiguous and malformed pixel coordinates at both descriptor boundaries', function (array $changes) {
  foreach ([$this->image, $this->mask] as $base) {
    $data = [...$base, 'sourcePixels' => array_replace($this->rect, $changes)];
    expect(fn() => $base['type'] === 'image' ? new CanvasCompositeOperation($data) : new CanvasCompositeMask($data))
      ->toThrow(InvalidArgumentException::class);
  }
})->with([[['x' => -1]], [['y' => -1]], [['width' => 0]], [['height' => 0]], [['width' => INF]],
  [['x' => NAN]], [['height' => '1']], [['width' => true]], [['x' => null]], [['extra' => 1]]]);

it('rejects mixed coordinate domains and null source views', function () {
  foreach ([$this->image, $this->mask] as $base) {
    foreach ([['sourcePixels' => $this->rect, 'source' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]],
      ['sourcePixels' => null]] as $fields) {
      expect(fn() => $base['type'] === 'image' ? new CanvasCompositeOperation([...$base, ...$fields])
        : new CanvasCompositeMask([...$base, ...$fields]))->toThrow(InvalidArgumentException::class);
    }
  }
});

it('checks current replacement dimensions for image masks and displacement masks without weakening source budgets', function () {
  $mask = [...$this->mask, 'sourcePixels' => $this->rect];
  $data = [...$this->image, 'sourcePixels' => $this->rect, 'masks' => [$mask],
    'displacement' => ['columns' => 2, 'rows' => 2, 'offsets' => array_fill(0, 4, [0, 0]), 'masks' => [$mask]]];
  $candidates = [$data, [...$this->image, 'masks' => [$mask]],
    [...$this->image, 'displacement' => $data['displacement']]];
  foreach ($candidates as $candidate) {
    $cost = CanvasImagePreflight::inspectCanvases([($this->makeCanvas)($candidate)], $this->root);
    expect($cost['sources'])->toBe(['source.png' => 11 * 7 * 4]);
  }
  unlink($this->root . '/source.png'); writeTestPng($this->root . '/source.png', 8, 7);
  foreach ($candidates as $candidate) {
    expect(fn() => CanvasImagePreflight::inspectCanvases([($this->makeCanvas)($candidate)], $this->root))
      ->toThrow(RuntimeException::class, 'PNG dimensions or crop exceed image bounds: source.png');
  }
  $image = new CanvasImage('integer', 'source.png', new CanvasRectangle(0, 0, 70, 30),
    sourceRect: new SpriteSourceRect(2, 2, 7, 3));
  expect(fn() => CanvasImagePreflight::inspect([$image], $this->root))
    ->toThrow(RuntimeException::class, 'PNG dimensions or crop exceed image bounds: source.png');
});

it('never sends unnegotiated pixel views including nested displacement masks', function (string $placement) {
  $mask = [...$this->mask, 'sourcePixels' => $this->rect];
  $data = match ($placement) {
    'image' => [...$this->image, 'sourcePixels' => $this->rect],
    'mask' => [...$this->image, 'masks' => [$mask]],
    'displacement' => [...$this->image, 'displacement' => ['columns' => 2, 'rows' => 2,
      'offsets' => array_fill(0, 4, [0, 0]), 'masks' => [$mask]]],
  };
  $canvas = ($this->makeCanvas)($data);
  expect(fn() => ($this->makePresenter)(false)->presentCanvas($canvas))
    ->toThrow(RendererProtocolException::class, 'canvas_source_pixels');
  expect(($this->makePresenter)(true)->presentCanvas($canvas))->toBeTrue();
})->with(['image', 'mask', 'displacement']);

it('retains normalized emission on older hosts and diagnoses precision once per live session', function () {
  $image = new CanvasImage('crop', 'source.png', new CanvasRectangle(0, 0, 70, 30), sourceRect: new SpriteSourceRect(2, 2, 7, 3));
  $tint = CanvasImageTint::compose('legacy', $image, PresentationColor::ansi16(1), .2, $this->root);
  expect($tint->operations[0]->data['masks'][0])->toHaveKey('source')->not->toHaveKey('sourcePixels');
  $canvas = new PresentationCanvas(100, 100, composites: [$tint]);
  $presenter = ($this->makePresenter)(false);
  $presenter->prepareCanvas($canvas); $presenter->prepareCanvas($canvas);
  $log = fn(): string => file_get_contents($this->root . '/logs/warning.log');
  expect(substr_count($log(), 'cannot guarantee atlas-cell isolation'))->toBe(1);
  $presenter->invalidate(newSession: true); $presenter->prepareCanvas($canvas);
  expect(substr_count($log(), 'cannot guarantee atlas-cell isolation'))->toBe(2);
});

it('negotiates pixel sources as an optional drawing feature with explicit prerequisites', function () {
  $session = new RendererSessionConfig('Pixels', $this->root, protocol: RendererProtocolVersion::V2);
  expect($session->getNegotiableCapabilities())->toContain(RendererSessionConfig::CANVAS_SOURCE_PIXELS)
    ->and($session->requiredCapabilities)->toBe([]);
  foreach ([[], ['graphical_canvas'], ['canvas_compositing']] as $caps) {
    expect(fn() => new RendererSessionConfig('Pixels', $this->root, protocol: RendererProtocolVersion::V2,
      requiredCapabilities: [...$caps, 'canvas_source_pixels']))->toThrow(InvalidArgumentException::class);
  }
  expect(new RendererSessionConfig('Pixels', $this->root, protocol: RendererProtocolVersion::V2,
    requiredCapabilities: ['graphical_canvas', 'canvas_compositing', 'canvas_source_pixels'])->requiredCapabilities)->toHaveCount(3);
});

it('keeps malformed region arguments separate from current PNG resource failures', function () {
  $preflight = \Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight::class;
  foreach ([[NAN, 0, 1, 1], [0, INF, 1, 1], [-1, 0, 1, 1], [0, 0, 0, 1], [0, 0, 1, -1]] as $rect) {
    expect(fn() => $preflight::inspectRegion($this->root, 'source.png', ...$rect))
      ->toThrow(InvalidArgumentException::class);
  }
  expect($preflight::inspectRegion($this->root, 'source.png', .5, .25, 10.5, 6.75))
    ->toBe(['width' => 10.5, 'height' => 6.75]);
  expect(fn() => $preflight::inspectRegion($this->root, 'source.png', .5, .25, 10.5001, 6.75))
    ->toThrow(RuntimeException::class, 'bounds: source.png');
  expect(fn() => $preflight::inspectRegion($this->root, 'missing.png', 0, 0, 1, 1))
    ->toThrow(RuntimeException::class, 'readable PNG');
});
