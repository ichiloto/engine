<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasComposite, CanvasCompositeBudget, CanvasCompositeOperation, CanvasRectangle, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\{RendererEvent, RendererGridConfig, RendererSessionConfig};
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\UI\Presentation\{MenuPresentationCatalog, MenuShapes};
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

function getDashedStroke(array $changes = []): array
{
  return ['type' => 'stroke', 'points' => [[2, 2], [2, 60], [90, 60]], 'width' => 1.5,
    'brush' => ['type' => 'solid', 'color' => ['kind' => 'rgb', 'r' => 1, 'g' => 2, 'b' => 3]], 'dash' => ['length' => 4, 'gap' => 4], ...$changes];
}

/** A presenter whose renderer advertised exactly these capabilities. */
function getDashPresenter(array $capabilities): array
{
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Dashes', __DIR__, protocol: RendererProtocolVersion::V2));
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => $capabilities]))];
  $client->pump();

  return [new RendererPresentation($client, new RendererGridConfig(1, 1)), $transport];
}

/** Synthetic shapes over a menu-sized area. */
function getDashShapes(bool $strokeDashes): MenuShapes
{
  return new MenuShapes(new MenuPresentationCatalog(__DIR__, ['schema' => 'ichiloto.menu/1']), new CanvasRectangle(0, 0, 1350, 720), $strokeDashes);
}

it('carries a dash of a length and a gap on a composite stroke, and refuses it anywhere else or out of range', function () {
  $operation = new CanvasCompositeOperation(getDashedStroke());

  expect($operation->data['dash'])->toBe(['length' => 4.0, 'gap' => 4.0])
    ->and(new CanvasCompositeOperation($operation->data)->data)->toBe($operation->data)
    ->and(new CanvasCompositeOperation(array_diff_key(getDashedStroke(), ['dash' => true]))->data)->not->toHaveKey('dash')
    // A dashed stroke asks the budget for a little more than a solid one.
    ->and(CanvasCompositeBudget::getWork([new CanvasComposite('dash', 100, 100, new CanvasRectangle(0, 0, 100, 100), [$operation])]))
    ->toBeGreaterThan(CanvasCompositeBudget::getWork([new CanvasComposite('solid', 100, 100, new CanvasRectangle(0, 0, 100, 100),
      [new CanvasCompositeOperation(array_diff_key(getDashedStroke(), ['dash' => true]))])]));
  foreach ([['dash' => ['length' => 0, 'gap' => 4]], ['dash' => ['length' => 4, 'gap' => -1]], ['dash' => ['length' => 4]],
    ['dash' => ['length' => 4, 'gap' => 4, 'offset' => 1]], ['dash' => 4]] as $changes) {
    expect(fn() => new CanvasCompositeOperation(getDashedStroke($changes)))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new CanvasCompositeOperation(['type' => 'fill', 'destination' => ['x' => 0, 'y' => 0, 'width' => 4, 'height' => 4],
    'brush' => ['type' => 'solid', 'color' => ['kind' => 'rgb', 'r' => 1, 'g' => 2, 'b' => 3]], 'dash' => ['length' => 4, 'gap' => 4]]))
    ->toThrow(InvalidArgumentException::class);
});

it('sends dashed strokes only to a renderer that negotiated canvas_stroke_dashes', function () {
  $canvas = new PresentationCanvas(100, 100, composites: [new CanvasComposite('lines', 100, 100, new CanvasRectangle(0, 0, 100, 100),
    [new CanvasCompositeOperation(getDashedStroke())])]);
  [$older, $olderPeer] = getDashPresenter(['graphical_canvas', 'canvas_compositing']);
  [$current, $peer] = getDashPresenter(['graphical_canvas', 'canvas_compositing', 'canvas_stroke_dashes']);

  expect(fn() => $older->presentCanvas($canvas))->toThrow(RendererProtocolException::class, 'canvas_stroke_dashes')
    ->and($olderPeer->sent)->toBe([])
    ->and($current->presentCanvas($canvas))->toBeTrue()
    ->and(RetainedFrameState::replay($peer->sent)[0]['canvas']['composites'][0]['operations'][0]['dash'])->toBe(['length' => 4.0, 'gap' => 4.0])
    // Offered to every v2 renderer, required by none, and only with the canvas it draws on.
    ->and(new RendererSessionConfig('Dashes', __DIR__, protocol: RendererProtocolVersion::V2)->getNegotiableCapabilities())
    ->toContain(RendererSessionConfig::CANVAS_STROKE_DASHES)
    ->and(fn() => new RendererSessionConfig('Dashes', __DIR__, protocol: RendererProtocolVersion::V2,
      requiredCapabilities: ['graphical_canvas', 'canvas_stroke_dashes']))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new RendererSessionConfig('Dashes', __DIR__, requiredCapabilities: ['canvas_stroke_dashes']))
    ->toThrow(InvalidArgumentException::class);
});

it('draws a dashed line of any length as one operation where the renderer dashes, and one per straight piece where it does not', function () {
  $path = [[100, 40], [100, 300], [1200, 300], [1200, 700]];
  foreach ([true, false] as $native) {
    $shapes = getDashShapes($native);
    $shapes->strokeDashedLine($path, 1.5, 4, 4, 'muted', 0.7);
    $composite = $shapes->compose('lines', 1);
    $operations = array_map(static fn(CanvasCompositeOperation $operation): array => $operation->data, $composite->operations);

    expect($operations)->toHaveCount($native ? 1 : 3);
    if ($native) {
      expect($operations[0]['dash'])->toBe(['length' => 4.0, 'gap' => 4.0])->and($operations[0]['masks'])->toBe([]);
    } else {
      // Each piece is a solid stroke masked to its dashes, within a mask's contour and point limits.
      foreach ($operations as $operation) {
        expect($operation)->not->toHaveKey('dash')
          ->and($operation['masks'])->toHaveCount(1)
          ->and(count($operation['masks'][0]['contours']))->toBeLessThanOrEqual(8);
      }
    }
    // The composite is admitted whole by the canvas and frame budgets.
    expect(new PresentationCanvas(1350, 720, composites: [$composite])->composites)->toHaveCount(1);
  }
});

it('keeps a board of many long dashed lines inside the frame\'s 256 operations either way', function () {
  foreach ([true, false] as $native) {
    $shapes = getDashShapes($native);
    // Twenty-four long curriculum paths down and across a wide board: once one operation per dash, now one per piece at most.
    for ($line = 0; $line < 24; $line++) {
      $shapes->strokeDashedLine([[675, 20], [675, 200 + $line], [40 + 50 * $line, 200 + $line], [40 + 50 * $line, 700]], 1.5, 4, 4, 'muted', 0.7);
    }
    $composite = $shapes->compose('board', 1);

    expect(count($composite->operations))->toBeLessThanOrEqual($native ? 24 : 72)
      ->and(new PresentationCanvas(1350, 720, composites: [$composite])->composites)->toHaveCount(1);
  }
});

it('lengthens the dashes of a renderer without stroke dashes rather than exceed a mask or the work budget', function () {
  $shapes = getDashShapes(false);
  // A long diagonal covers a large box: its mask stays small enough to admit, with longer dashes in the same rhythm.
  $shapes->strokeDashedLine([[0, 0], [1349, 719]], 2, 3, 3, 'muted');
  $shapes->strokeDashedLine([[0, 360], [1349, 360]], 2, 1, 1, 'muted');
  $composite = $shapes->compose('long', 1);
  $points = array_map(static fn(CanvasCompositeOperation $operation): int => array_sum(array_map(count(...),
    $operation->data['masks'][0]['contours'])), $composite->operations);

  expect($points[0])->toBeLessThan($points[1])
    ->and(max($points))->toBeLessThanOrEqual(8 * 128)
    ->and(CanvasCompositeBudget::getWork([$composite]))->toBeLessThan(CanvasCompositeBudget::MAX_WORK / 8)
    ->and(new PresentationCanvas(1350, 720, composites: [$composite])->composites)->toHaveCount(1);
});

it('parts a ring around the areas it is told to leave clear, a square one as a disc', function () {
  $shapes = getDashShapes(true);
  $shapes->strokeCircle(200, 200, 40, 2, 'focus', clear: [new CanvasRectangle(220, 228, 20, 20), new CanvasRectangle(180, 230, 30, 17)]);
  $masks = $shapes->compose('ring', 1)->operations[0]->data['masks'];
  $clears = array_values(array_filter($masks, static fn(array $mask): bool => $mask['type'] === 'polygon'));

  expect($clears)->toHaveCount(2)
    ->and(array_column($clears, 'invert'))->toBe([true, true]);
});
