<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

it('keeps logical nine-slice corners and shared edges identical at both densities', function () {
  $destination = new CanvasRectangle(0.25, 0.5, 140.5, 120.25);
  $layouts = [];
  foreach ([1, 2] as $density) {
    $texture = new CanvasNineSlice('panel.png', new SpriteSourceRect(0, 0, 96 * $density, 96 * $density),
      24 * $density, 24 * $density, 24 * $density, 24 * $density, $density, 80, 64);
    $images = $texture->images('panel', $destination, 1000);
    expect($images)->toHaveCount(9)
      ->and($images[0]->destination->width)->toBe(24.0)
      ->and($images[0]->sourceRect->width)->toBe(24 * $density);
    foreach ([0, 3, 6] as $row) {
      expect($images[$row]->destination->x + $images[$row]->destination->width)->toBe($images[$row + 1]->destination->x)
        ->and($images[$row + 1]->destination->x + $images[$row + 1]->destination->width)->toBe($images[$row + 2]->destination->x);
    }
    $layouts[] = array_map(fn($image) => $image->destination->toArray(), $images);
    new PresentationCanvas(200, 200, $images);
  }
  expect($layouts[0])->toBe($layouts[1]);
});

it('composes three-slice tracks without degenerate patches and rejects undersized corners', function () {
  $track = new CanvasNineSlice('track.png', new SpriteSourceRect(0, 0, 24, 12), 6, 0, 6, 0, minimumWidth: 24);
  expect($track->images('track', new CanvasRectangle(0, 0, 100, 12), 1))->toHaveCount(3);
  expect(fn() => $track->images('track', new CanvasRectangle(0, 0, 10, 12), 1))->toThrow(InvalidArgumentException::class);
  expect(fn() => new CanvasNineSlice('track.png', new SpriteSourceRect(0, 0, 24, 12), 13, 0, 12, 0))
    ->toThrow(InvalidArgumentException::class);
});

it('clips full-width gauge images without changing their source or destination', function () {
  $texture = new CanvasNineSlice('fill.png', new SpriteSourceRect(0, 0, 8, 8));
  $destination = new CanvasRectangle(12, 22, 96, 8);
  $half = $texture->images('fill', $destination, 1, new CanvasRectangle(12, 22, 48.25, 8))[0];
  $full = $texture->images('fill', $destination, 1, $destination)[0];
  expect($half->sourceRect)->toEqual($full->sourceRect)->and($half->destination)->toEqual($full->destination)
    ->and($half->toArray()['clipRect']['width'])->toBe(48.25);
});

it('validates clipping without using it to permit off-canvas original geometry', function () {
  $rect = new CanvasRectangle(0, 0, 10, 10);
  $disjoint = new CanvasRectangle(50, 50, 10, 10);
  $image = new CanvasImage('image', 'a.png', $rect, clipRect: $disjoint);
  $text = new CanvasTextLayer('text', 1, 0, 0, new RendererGridConfig(1, 1, 10, 10),
    [new PresentationTextRun(0, 0, 'A')], $disjoint, 0.5);
  expect(new PresentationCanvas(60, 60, [$image], textLayers: [$text])->textLayers[0]->toArray()['opacity'])->toBe(0.5);
  expect(fn() => new PresentationCanvas(59, 60, [$image]))->toThrow(InvalidArgumentException::class);
  expect(fn() => new PresentationCanvas(59, 60, textLayers: [$text]))->toThrow(InvalidArgumentException::class);
  expect(fn() => new PresentationCanvas(5, 5, [new CanvasImage('image', 'a.png', $rect,
    clipRect: new CanvasRectangle(0, 0, 1, 1))]))->toThrow(InvalidArgumentException::class);
  foreach ([NAN, INF, -0.1, 1.01] as $opacity) {
    expect(fn() => new CanvasTextLayer('text', 0, 0, 0, new RendererGridConfig(1, 1), [], opacity: $opacity))
      ->toThrow(InvalidArgumentException::class);
  }
});

it('requires canvas for compositing and preserves omitted legacy defaults', function () {
  expect(fn() => new RendererSessionConfig('UI', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: [RendererSessionConfig::CANVAS_CLIP_OPACITY]))->toThrow(InvalidArgumentException::class);
  expect(new RendererSessionConfig('UI', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_CLIP_OPACITY])->hello()->payload)
    ->toHaveKey('requiredCapabilities');
  expect(new CanvasTextLayer('text', 0, 0, 0, new RendererGridConfig(1, 1), [])->toArray())
    ->not->toHaveKey('opacity')->not->toHaveKey('clipRect');
});
