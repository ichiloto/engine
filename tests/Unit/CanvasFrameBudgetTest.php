<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasComposite, CanvasCompositeBudget, CanvasCompositeOperation, CanvasFrameBudget,
  CanvasImage, CanvasIndicator, CanvasIndicatorKind, CanvasLabel, CanvasRectangle, CanvasTextLayer, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\{PresentationColor, PresentationTextRun};
use Ichiloto\Engine\Rendering\Presentation\Text\{TextBounds, TextMeasurement, TextMetrics};
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;

function createFrameBudgetImage(string $id = 'image'): CanvasImage
{
  return new CanvasImage($id, 'synthetic.png', new CanvasRectangle(0, 0, 1, 1));
}

function createFrameBudgetText(array $runs = [], string $id = 'grid'): CanvasTextLayer
{
  return new CanvasTextLayer($id, 0, 0, 0, new RendererGridConfig(512, 1, 1, 1), $runs);
}

function createFrameBudgetFill(int $size = 16): CanvasCompositeOperation
{
  return new CanvasCompositeOperation(['type' => 'fill',
    'destination' => ['x' => 0, 'y' => 0, 'width' => $size, 'height' => $size],
    'brush' => ['type' => 'solid', 'color' => ['kind' => 'ansi16', 'index' => 1]]]);
}

function createFrameBudgetComposite(int $size = 16, array $operations = []): PresentationCanvas
{
  return new PresentationCanvas(512, 1, composites: [new CanvasComposite('composite', $size, $size,
    new CanvasRectangle(0, 0, 1, 1), $operations)]);
}

it('requires a typed list bounded before visiting or flattening frame roots', function () {
  $empty = new PresentationCanvas(1, 1);
  CanvasFrameBudget::assertCanvases([]);
  CanvasFrameBudget::assertCanvases(array_fill(0, 1025, $empty));
  foreach ([array_fill(0, 1026, $empty), ['root' => $empty], [new stdClass()], [null]] as $roots) {
    expect(fn() => CanvasFrameBudget::assertCanvases($roots))->toThrow(InvalidArgumentException::class);
  }
});

it('counts every root against the shared 1024 image pool even when drawing models are reused', function () {
  $images = array_map(fn($i) => createFrameBudgetImage('image-' . $i), range(1, 512));
  $root = new PresentationCanvas(512, 1, $images);
  CanvasFrameBudget::assertCanvases([$root, $root]);
  expect(fn() => CanvasFrameBudget::assertCanvases([$root, $root,
    new PresentationCanvas(512, 1, [createFrameBudgetImage()])]))->toThrow(InvalidArgumentException::class);
});

it('shares the 2048 indicator pool without changing each roots image ownership', function () {
  $image = createFrameBudgetImage();
  $indicators = array_map(fn($i) => new CanvasIndicator('indicator-' . $i, $image->id,
    CanvasIndicatorKind::OUTLINE, $image->destination, 1, PresentationColor::ansi16(15)), range(1, 1024));
  $root = new PresentationCanvas(512, 1, [$image], $indicators);
  CanvasFrameBudget::assertCanvases([$root, $root]);
  expect(fn() => CanvasFrameBudget::assertCanvases([$root, $root,
    new PresentationCanvas(512, 1, [$image], [$indicators[0]])]))->toThrow(InvalidArgumentException::class);
});

it('counts reused label placements separately from the unchanged grid-layer budget', function () {
  $bounds = new TextBounds(0, 0, 1, 1);
  $measurement = new TextMeasurement('reused-layout', new TextMetrics(1, 1, $bounds, $bounds, $bounds));
  $labels = array_map(fn($i) => new CanvasLabel('label-' . $i, $measurement, 0, 0), range(1, 512));
  $grids = array_map(fn($i) => createFrameBudgetText(id: 'grid-' . $i), range(1, 32));
  $labelRoot = new PresentationCanvas(512, 1, labels: $labels);
  $gridRoot = new PresentationCanvas(512, 1, textLayers: $grids);
  CanvasFrameBudget::assertCanvases([$labelRoot, $labelRoot, $gridRoot, $gridRoot]);
  foreach ([$labelRoot, $gridRoot] as $extra) {
    expect(fn() => CanvasFrameBudget::assertCanvases([$labelRoot, $labelRoot, $gridRoot, $gridRoot, $extra]))
      ->toThrow(InvalidArgumentException::class);
  }
});

it('counts the aggregate 32768 grid runs including empty reused text draws', function () {
  $runs = array_fill(0, 16384, new PresentationTextRun(0, 0, ''));
  $root = new PresentationCanvas(512, 1, textLayers: [createFrameBudgetText($runs)]);
  CanvasFrameBudget::assertCanvases([$root, $root]);
  $extra = new PresentationCanvas(512, 1, textLayers: [createFrameBudgetText([$runs[0]])]);
  expect(fn() => CanvasFrameBudget::assertCanvases([$root, $root, $extra]))->toThrow(InvalidArgumentException::class);
});

it('counts Unicode scalars rather than bytes or graphemes at the shared 524288 boundary', function () {
  $text = str_repeat("\u{00E9}\u{1F642}\u{0061}\u{0301}", 128);
  expect(mb_strlen($text, 'UTF-8'))->toBe(512)->and(strlen($text))->toBeGreaterThan(512);
  $root = new PresentationCanvas(512, 1, textLayers: [createFrameBudgetText(
    array_fill(0, 512, new PresentationTextRun(0, 0, $text)))]);
  CanvasFrameBudget::assertCanvases([$root, $root]);
  $extra = new PresentationCanvas(512, 1, textLayers: [createFrameBudgetText([new PresentationTextRun(0, 0, "\u{05D0}")])]);
  expect(fn() => CanvasFrameBudget::assertCanvases([$root, $root, $extra]))->toThrow(InvalidArgumentException::class);
});

it('bounds the composite pool at eight before flattening reused local drawings', function () {
  $root = createFrameBudgetComposite();
  CanvasFrameBudget::assertCanvases(array_fill(0, 8, $root));
  expect(fn() => CanvasFrameBudget::assertCanvases(array_fill(0, 9, $root)))->toThrow(InvalidArgumentException::class);
});

it('enforces the shared 8388608 composite pixel boundary across individually valid roots', function () {
  $large = createFrameBudgetComposite(2048);
  CanvasFrameBudget::assertCanvases([$large, $large]);
  expect(fn() => CanvasFrameBudget::assertCanvases([$large, $large, createFrameBudgetComposite(1)]))
    ->toThrow(InvalidArgumentException::class);
});

it('enforces the shared 256 operation boundary across individually valid roots', function () {
  $fill = createFrameBudgetFill();
  $root = createFrameBudgetComposite(16, array_fill(0, 128, $fill));
  CanvasFrameBudget::assertCanvases([$root, $root]);
  expect(fn() => CanvasFrameBudget::assertCanvases([$root, $root, createFrameBudgetComposite(16, [$fill])]))
    ->toThrow(InvalidArgumentException::class);
});

it('enforces the shared 16384 displacement node boundary across individually valid roots', function () {
  $createImage = fn(int $size) => new CanvasCompositeOperation(['type' => 'image', 'asset' => 'synthetic.png',
    'destination' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 16],
    'displacement' => ['columns' => $size, 'rows' => $size, 'offsets' => array_fill(0, $size * $size, [0, 0])]]);
  $root = createFrameBudgetComposite(16, [$createImage(64)]);
  CanvasFrameBudget::assertCanvases(array_fill(0, 4, $root));
  expect(fn() => CanvasFrameBudget::assertCanvases([...array_fill(0, 4, $root),
    createFrameBudgetComposite(16, [$createImage(2)])]))->toThrow(InvalidArgumentException::class);
});

it('checks aggregate cold work at 268435456 instead of validating only each local root', function () {
  $fill = createFrameBudgetFill(2048);
  $first = createFrameBudgetComposite(2048, array_fill(0, 8, $fill));
  $second = createFrameBudgetComposite(2048, array_fill(0, 7, $fill));
  expect(CanvasCompositeBudget::getWork([...$first->composites, ...$second->composites]))->toBe(268435456);
  CanvasFrameBudget::assertCanvases([$first, $second]);
  expect(fn() => CanvasFrameBudget::assertCanvases([$first, $first]))
    ->toThrow(InvalidArgumentException::class, 'cold raster work');
});
