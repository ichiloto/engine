<?php

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasImage, CanvasIndicator, CanvasIndicatorKind, CanvasLabel, CanvasRectangle, CanvasTextLayer, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasViewportFit;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\Text\{TextBounds, TextMeasurement, TextMetrics};
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Presentation\{MenuCanvas, MenuPresentationCatalog};

/** A menu overlay stacks every kind of element its source carries, above everything in the base. Synthetic canvases only. */

function createOverlayTestTheme(): MenuPresentationCatalog
{
  return new MenuPresentationCatalog(__DIR__ . '/../Fixtures/Renderer', ['schema' => 'ichiloto.menu/1']);
}

it('keeps the complete scene fitting choice while stacking shared menu overlays', function () {
  $base = new PresentationCanvas(100, 100, viewportFit: CanvasViewportFit::CONTAIN);
  $combined = MenuCanvas::overlay($base, new PresentationCanvas(100, 100), createOverlayTestTheme());
  expect($combined->viewportFit)->toBe(CanvasViewportFit::CONTAIN);
});

function createOverlayTestLabel(string $id, int $layer, ?CanvasRectangle $clip = null, float $opacity = 1, int $rotation = 0): CanvasLabel
{
  $bounds = new TextBounds(0, 0, 12, 8);

  return new CanvasLabel($id, new TextMeasurement("{$id}-layout", new TextMetrics(12, 6, $bounds, $bounds, $bounds)),
    10, 20, $layer, $clip, $opacity, $rotation);
}

it('carries the overlay\'s labels above every base layer and label, keeping their layout, clip and opacity', function () {
  $base = new PresentationCanvas(100, 100, [new CanvasImage('backdrop', 'test-sprite.png', new CanvasRectangle(0, 0, 8, 8), 3)],
    labels: [createOverlayTestLabel('base-label', 9)]);
  $clip = new CanvasRectangle(5, 15, 30, 20);
  $overlay = new PresentationCanvas(100, 100, labels: [createOverlayTestLabel('card-index', 2, $clip, 0.5, 180)]);

  $combined = MenuCanvas::overlay($base, $overlay, createOverlayTestTheme());
  [$kept, $added] = $combined->labels;

  expect(array_column($combined->labels, 'id'))->toBe(['base-label', 'card-index'])
    ->and($kept->layer)->toBe(9)
    // Above the base label at 9, not only above its images.
    ->and($added->layer)->toBe(12)
    ->and($added->measurement->layoutId)->toBe('card-index-layout')
    ->and($added->clipRect)->toEqual($clip)
    ->and($added->opacity)->toBe(0.5)
    // An opposite-seat card index keeps its half turn.
    ->and($added->rotation)->toBe(180)
    ->and([$added->x, $added->y])->toBe([10.0, 20.0]);
});

it('keeps the overlay\'s mirrored images mirrored', function () {
  $base = new PresentationCanvas(100, 100);
  $overlay = new PresentationCanvas(100, 100, [new CanvasImage('opposite-card', 'test-sprite.png', new CanvasRectangle(4, 4, 8, 8), 1,
    flipX: true, flipY: true)]);

  $image = array_find(MenuCanvas::overlay($base, $overlay, createOverlayTestTheme())->images,
    static fn(CanvasImage $image): bool => $image->id === 'opposite-card');

  expect($image)->not->toBeNull()
    ->and([$image->flipX, $image->flipY])->toBe([true, true]);
});

it('carries the overlay\'s focus outline with the image it marks, above a high base label', function () {
  $base = new PresentationCanvas(100, 100, labels: [createOverlayTestLabel('base-label', 40)]);
  $card = new CanvasImage('focused-card', 'test-sprite.png', new CanvasRectangle(4, 4, 8, 8), 1);
  $focus = new CanvasIndicator('card-focus', 'focused-card', CanvasIndicatorKind::OUTLINE, new CanvasRectangle(3, 3, 10, 10), 1,
    PresentationColor::rgb(255, 255, 255), 2);
  $overlay = new PresentationCanvas(100, 100, [$card], [$focus]);

  $combined = MenuCanvas::overlay($base, $overlay, createOverlayTestTheme());
  $image = array_find($combined->images, static fn(CanvasImage $image): bool => $image->id === 'focused-card');

  expect(array_column($combined->indicators, 'id'))->toBe(['card-focus'])
    ->and($combined->indicators[0]->imageId)->toBe('focused-card')
    ->and($combined->indicators[0]->kind)->toBe(CanvasIndicatorKind::OUTLINE)
    // Both stay above the base label at 40, the outline above its card.
    ->and($image->layer)->toBe(42)
    ->and($combined->indicators[0]->layer)->toBe(43)
    ->and($combined->getOverlayProtection())->toContainEqual(new CanvasRectangle(3, 3, 10, 10));
});

it('still leaves out the overlay\'s own whole-screen background while protecting what it covered', function () {
  $theme = createOverlayTestTheme();
  $grid = new RendererGridConfig(4, 1, 8, 16);
  $background = new CanvasTextLayer('menu-background', 0, 0, 0, $grid, [new PresentationTextRun(0, 0, '    ')]);
  $prompt = new CanvasTextLayer('prompt', 1, 0, 16, $grid, [new PresentationTextRun(0, 0, 'Deal')]);
  $overlay = new PresentationCanvas(100, 100, textLayers: [$background, $prompt]);

  $combined = MenuCanvas::overlay(new PresentationCanvas(100, 100), $overlay, $theme);
  $ids = array_merge(...array_map(static fn(CanvasTextLayer $text): array => array_column($text->runs, 'text'), $combined->textLayers));

  expect($ids)->toContain('Deal')
    ->and(array_column($combined->textLayers, 'id'))->not->toContain('menu-background')
    ->and($combined->getOverlayProtection())->toEqual($overlay->getOverlayProtection());
});


it('batches clipped separator grids without widening painted rows or changing opacity', function () {
  $color = PresentationColor::rgb(30, 60, 90);
  $makeRows = static function (int $start) use ($color): array {
    $rows = [];
    for ($i = $start; $i < $start + 40; $i++) {
      $rows[] = new CanvasTextLayer('rule-' . $i . '-separator', 3, 10, $i * 8,
        new RendererGridConfig(5, 1, 208, 1),
        [new PresentationTextRun(0, 0, '     ', background: $color)],
        new CanvasRectangle(10, $i * 8, 1036, 1), 0.25);
    }
    return $rows;
  };
  $base = new PresentationCanvas(1350, 720, textLayers: $makeRows(0));
  $overlay = new PresentationCanvas(1350, 720, textLayers: $makeRows(40));
  $paintedRows = static function (array $layers): array {
    $rows = [];
    foreach ($layers as $layer) {
      foreach ($layer->runs as $run) {
        $left = max($layer->x + $run->column * $layer->grid->cellWidth, $layer->clipRect->x);
        $right = min($layer->x + ($run->column + strlen($run->text)) * $layer->grid->cellWidth,
          $layer->clipRect->x + $layer->clipRect->width);
        $rows[] = [$layer->y + $run->row * $layer->grid->cellHeight, $left, $right,
          $run->background, $layer->opacity];
      }
    }
    usort($rows, static fn($a, $b) => $a[0] <=> $b[0]);
    return $rows;
  };
  $combined = MenuCanvas::overlay($base, $overlay, createOverlayTestTheme());
  expect(count($combined->textLayers))->toBeLessThanOrEqual(64)
    ->and($paintedRows($combined->textLayers))->toEqual($paintedRows([...$base->textLayers, ...$overlay->textLayers]));
});
