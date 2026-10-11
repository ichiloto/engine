<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasImage, CanvasLabel, CanvasRectangle, CanvasTextLayer, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\Text\{TextBounds, TextMeasurement, TextMetrics};
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;

function createCanvasLabelMeasurement(?TextBounds $paint = null): TextMeasurement
{
  $paint ??= new TextBounds(-2.5, -1.25, 10, 8);
  return new TextMeasurement('synthetic-layout', new TextMetrics(8, 6,
    new TextBounds(0, 0, 8, 7), new TextBounds(-1, 0, 9, 7), $paint));
}

it('serializes only a live layout reference and origin while preserving signed paint bearings', function () {
  $measurement = createCanvasLabelMeasurement();
  $label = new CanvasLabel('label', $measurement, 2.5, 1.25, -5);
  expect($label->paintBounds->toArray())->toBe(['x' => 0.0, 'y' => 0.0, 'width' => 10.0, 'height' => 8.0])
    ->and($label->toArray())->toBe(['id' => 'label', 'layoutId' => 'synthetic-layout',
      'origin' => ['x' => 2.5, 'y' => 1.25], 'layer' => -5])
    ->and($label->measurement)->toBe($measurement);
  $canvas = new PresentationCanvas(10, 8, labels: [$label]);
  expect($canvas->toArray()['labels'])->toBe([$label->toArray()])
    ->and(new PresentationCanvas(10, 8)->toArray())->not->toHaveKey('labels');
  expect(fn() => new ReflectionProperty($label, 'x')->setValue($label, 3))->toThrow(Error::class);
});

it('accepts signed origins when their translated paint fits and preserves legitimate empty bounds', function () {
  $label = new CanvasLabel('signed-origin', createCanvasLabelMeasurement(new TextBounds(2, 3, 10, 8)), -2, -3);
  expect(new PresentationCanvas(10, 8, labels: [$label])->labels[0]->x)->toBe(-2.0);
  foreach ([[0, 0], [0, 8], [10, 0]] as [$width, $height]) {
    $empty = new CanvasLabel('empty', createCanvasLabelMeasurement(new TextBounds(0, 0, $width, $height)), 0, 0);
    expect(new PresentationCanvas(10, 8, labels: [$empty])->getOverlayProtection())->toBe([]);
  }
  $clip = new CanvasRectangle(0, 0, 1, 1);
  $empty = new CanvasLabel('empty', createCanvasLabelMeasurement(new TextBounds(0, 0, 0, 0)), 10, 8, clipRect: $clip);
  expect(new PresentationCanvas(10, 8, labels: [$empty])->getOverlayProtection())->toBe([$clip]);
});

it('rejects nonfinite label origins opacity and out of range layers', function () {
  $measurement = createCanvasLabelMeasurement();
  foreach ([INF, -INF, NAN] as $value) {
    expect(fn() => new CanvasLabel('x', $measurement, $value, 2))->toThrow(InvalidArgumentException::class);
    expect(fn() => new CanvasLabel('y', $measurement, 3, $value))->toThrow(InvalidArgumentException::class);
    expect(fn() => new CanvasLabel('opacity', $measurement, 3, 2, opacity: $value))->toThrow(InvalidArgumentException::class);
  }
  foreach ([-0.01, 1.01] as $value) {
    expect(fn() => new CanvasLabel('opacity', $measurement, 3, 2, opacity: $value))->toThrow(InvalidArgumentException::class);
  }
  foreach ([-2147483649, 2147483648] as $layer) {
    expect(fn() => new CanvasLabel('layer', $measurement, 3, 2, $layer))->toThrow(InvalidArgumentException::class);
  }
  foreach ([-2147483648, 2147483647] as $layer) {
    expect(new CanvasLabel('layer', $measurement, 3, 2, $layer)->layer)->toBe($layer);
  }
});

it('refuses invalid label identifiers through the shared UTF-8 byte contract', function () {
  foreach (['', str_repeat('a', 257), "bad\n", "bad\0", "\xFF", str_repeat("\u{00E9}", 129)] as $id) {
    expect(fn() => new CanvasLabel($id, createCanvasLabelMeasurement(), 3, 2))->toThrow(InvalidArgumentException::class);
  }
  $id = str_repeat("\u{00E9}", 128);
  expect(new CanvasLabel($id, createCanvasLabelMeasurement(), 3, 2)->id)->toBe($id);
});

it('does not let a crop or zero opacity excuse invalid effect paint geometry', function () {
  $measurement = createCanvasLabelMeasurement();
  $clip = new CanvasRectangle(0, 0, 1, 1);
  foreach ([[2.49, 1.25], [2.5, 1.24], [16377, 2], [3, 16379]] as [$x, $y]) {
    expect(fn() => new CanvasLabel('overflow', $measurement, $x, $y, clipRect: $clip, opacity: 0))
      ->toThrow(InvalidArgumentException::class);
  }
  foreach ([[2.51, 1.25], [2.5, 1.26]] as [$x, $y]) {
    $label = new CanvasLabel('overflow', $measurement, $x, $y, clipRect: $clip, opacity: 0);
    expect(fn() => new PresentationCanvas(10, 8, labels: [$label]))->toThrow(InvalidArgumentException::class);
  }
  $label = new CanvasLabel('clip', $measurement, 2.5, 1.25, clipRect: new CanvasRectangle(9, 0, 2, 1));
  expect(fn() => new PresentationCanvas(10, 8, labels: [$label]))->toThrow(InvalidArgumentException::class);
});

it('protects translated label paint or explicit clips and retains annotated protection semantics', function () {
  $paint = new CanvasLabel('paint', createCanvasLabelMeasurement(), 5, 4);
  $clip = new CanvasRectangle(8, 8, 1, 1);
  $clipped = new CanvasLabel('clipped', createCanvasLabelMeasurement(), 5, 4, clipRect: $clip, opacity: 0.5);
  $canvas = new PresentationCanvas(20, 20, labels: [$paint, $clipped]);
  expect(array_map(fn($rect) => $rect->toArray(), $canvas->getOverlayProtection()))
    ->toBe([['x' => 2.5, 'y' => 2.75, 'width' => 10.0, 'height' => 8.0], $clip->toArray()])
    ->and($clipped->toArray()['clipRect'])->toBe($clip->toArray())
    ->and($clipped->toArray()['opacity'])->toBe(0.5)
    ->and(new PresentationCanvas(20, 20, protectedAreas: [], labels: [$paint])->getOverlayProtection())->toBe([]);
});

it('preserves relayers and prefixes labels while stacking all overlay pools above label layers', function () {
  $measurement = createCanvasLabelMeasurement();
  $clip = new CanvasRectangle(0, 0, 10, 8);
  $base = new PresentationCanvas(20, 20, labels: [new CanvasLabel('label', $measurement, 3, 2, 20)]);
  $overlayLabel = new CanvasLabel('label', $measurement, 4, 3, 2, $clip, 0.5);
  $image = new CanvasImage('image', 'synthetic.png', new CanvasRectangle(0, 0, 1, 1), 1);
  $overlay = new PresentationCanvas(20, 20, [$image], labels: [$overlayLabel]);
  $combined = PresentationCanvas::composeOverlay($base, $overlay, 'overlay:');
  expect(array_column($combined->labels, 'id'))->toBe(['label', 'overlay:label'])
    ->and(array_column($combined->labels, 'layer'))->toBe([20, 23])
    ->and($combined->images[0]->layer)->toBe(22)
    ->and($combined->labels[1]->measurement)->toBe($measurement)
    ->and($combined->labels[1]->toArray())->toBe([...$overlayLabel->toArray(), 'id' => 'overlay:label', 'layer' => 23])
    ->and($combined->getOverlayProtection())->toEqual([...$base->getOverlayProtection(), ...$overlay->getOverlayProtection()])
    ->and(PresentationCanvas::composeOverlay(null, $overlay))->toBe($overlay);
  expect(fn() => PresentationCanvas::composeOverlay($base, $overlay))->toThrow(InvalidArgumentException::class);
  expect(PresentationCanvas::composeOverlay(null, $overlay, 'only:')->labels[0]->id)->toBe('only:label');
});

it('copies label lists detaching references with stable layer sorting and pool-local IDs', function () {
  $first = new CanvasLabel('shared-id', createCanvasLabelMeasurement(), 3, 2, 5);
  $second = new CanvasLabel('second', $first->measurement, 3, 2, 5);
  $low = new CanvasLabel('low', $first->measurement, 3, 2, -1);
  $grid = new CanvasTextLayer('shared-id', 0, 0, 0, new RendererGridConfig(1, 1, 1, 1), []);
  $canvas = new PresentationCanvas(20, 20, textLayers: [$grid], labels: [&$first, $second, $low]);
  $first = new CanvasLabel('replacement', $second->measurement, 3, 2);
  expect(array_column($canvas->labels, 'id'))->toBe(['low', 'shared-id', 'second']);
  foreach ([[$second, $second], ['named' => $second], [new stdClass()]] as $labels) {
    expect(fn() => new PresentationCanvas(20, 20, labels: $labels))->toThrow(InvalidArgumentException::class);
  }
});

it('shares the local 64 entry pool with legacy grid text while retaining old positional arguments', function () {
  $measurement = createCanvasLabelMeasurement();
  $labels = array_map(fn($i) => new CanvasLabel('label-' . $i, $measurement, 3, 2), range(1, 64));
  expect(new PresentationCanvas(20, 20, [], [], [], [], [], ['owner'], $labels)->labels)->toHaveCount(64);
  expect(fn() => new PresentationCanvas(20, 20, labels: [...$labels, new CanvasLabel('extra', $measurement, 3, 2)]))
    ->toThrow(InvalidArgumentException::class);
  $grid = new CanvasTextLayer('grid', 0, 0, 0, new RendererGridConfig(1, 1, 1, 1), []);
  expect(new PresentationCanvas(20, 20, textLayers: [$grid], labels: array_slice($labels, 0, 63))->labels)->toHaveCount(63);
  expect(fn() => new PresentationCanvas(20, 20, textLayers: [$grid], labels: $labels))->toThrow(InvalidArgumentException::class);
});
