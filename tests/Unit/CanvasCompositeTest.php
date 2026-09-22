<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasComposite, CanvasCompositeBrush, CanvasCompositeBudget, CanvasCompositeMask, CanvasCompositeOperation, CanvasImagePreflight, CanvasRectangle, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\{RendererEvent, RendererGridConfig, RendererSessionConfig};
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\UI\Presentation\{MenuCanvas, MenuPresentationCatalog};
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function getCompositeTestRect(int $size = 16): array
{
  return ['x' => 0, 'y' => 0, 'width' => $size, 'height' => $size];
}

function getCompositeTestFill(int $size = 16): array
{
  return ['type' => 'fill', 'destination' => getCompositeTestRect($size),
    'brush' => ['type' => 'solid', 'color' => ['kind' => 'rgb', 'r' => 1, 'g' => 2, 'b' => 3]]];
}

it('serializes bounded raster operations without changing legacy canvas packets', function () {
  $operation = new CanvasCompositeOperation(getCompositeTestFill());
  $composite = new CanvasComposite('effect', 16, 16, new CanvasRectangle(2, 3, 32, 32), [$operation], 5, 0.4);
  $canvas = new PresentationCanvas(100, 100, composites: [$composite]);
  expect(new PresentationCanvas(100, 100)->toArray())->not->toHaveKey('composites')
    ->and($canvas->toArray()['composites'][0]['opacity'])->toBe(0.4)
    ->and($canvas->toArray()['composites'][0]['operations'][0]['blend'])->toBe('source_over')
    ->and(CanvasCompositeBudget::getWork([$composite]))->toBe(16 * 16 * 6);
});

it('rejects malformed operation fields at the PHP boundary', function (array $changes) {
  expect(fn() => new CanvasCompositeOperation(array_replace(getCompositeTestFill(), $changes)))
    ->toThrow(InvalidArgumentException::class);
})->with([
  [['opacity' => null]], [['opacity' => '1']], [['opacity' => NAN]], [['opacity' => INF]],
  [['opacity' => 1.01]], [['blend' => 'multiply']], [['time' => 1]], [['masks' => null]],
  [['destination' => ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 1]]],
  [['brush' => ['type' => 'solid', 'color' => '#FFFFFF']]],
]);

it('detaches nested reference inputs and validates finite displacement fields', function () {
  $x = 1;
  $offset = [&$x, 2];
  $data = ['type' => 'image', 'asset' => 'synthetic.png', 'destination' => getCompositeTestRect(),
    'source' => ['x' => 0.25, 'y' => 0.25, 'width' => 0.5, 'height' => 0.5],
    'displacement' => ['columns' => 2, 'rows' => 2, 'offsets' => [&$offset, $offset, $offset, $offset]]];
  $operation = new CanvasCompositeOperation($data);
  $x = 100; $offset = [8, 9];
  expect($operation->data['displacement']['offsets'][0])->toBe([1.0, 2.0]);
  foreach ([['columns' => 1], ['rows' => 65], ['columns' => '2'], ['offsets' => [[1, 2]]],
    ['offsets' => array_fill(0, 4, [4097, 0])]] as $changes) {
    $bad = $data; $bad['displacement'] = array_replace($data['displacement'], $changes);
    expect(fn() => new CanvasCompositeOperation($bad))->toThrow(InvalidArgumentException::class);
  }
});

it('validates gradient endpoints and strict masks while allowing clipped path coordinates', function () {
  $color = ['kind' => 'ansi16', 'index' => 15];
  $brush = ['type' => 'linear', 'start' => [-2, -3], 'end' => [20, 20],
    'stops' => [['offset' => 0, 'color' => $color], ['offset' => 1, 'color' => $color, 'opacity' => 0]]];
  expect(new CanvasCompositeBrush($brush)->data['stops'][1]['opacity'])->toBe(0.0);
  foreach ([['start' => [20, 20]], ['stops' => [['offset' => 0.1, 'color' => $color], ['offset' => 1, 'color' => $color]]],
    ['stops' => [['offset' => 0, 'color' => $color], ['offset' => 0, 'color' => $color]]]] as $changes) {
    expect(fn() => new CanvasCompositeBrush(array_replace($brush, $changes)))->toThrow(InvalidArgumentException::class);
  }
  $mask = ['type' => 'polygon', 'contours' => [[[-2, -2], [16, 0], [16, 16]]], 'feather' => 2, 'invert' => true];
  expect(new CanvasCompositeMask($mask)->data['invert'])->toBeTrue();
  foreach ([['contours' => [[[0, 0], [0, 0], [16, 16]]]], ['feather' => -1], ['invert' => 1], ['unused' => true]] as $changes) {
    expect(fn() => new CanvasCompositeMask(array_replace($mask, $changes)))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new CanvasCompositeMask(['type' => 'ellipse', 'center' => [0, 0], 'radius' => [0, 2]]))
    ->toThrow(InvalidArgumentException::class);
});

it('enforces raster aggregate operation node and cold-work limits', function () {
  $bounds = new CanvasRectangle(0, 0, 16, 16);
  foreach ([[0, 1], [4097, 1], [4096, 4096]] as [$width, $height]) {
    expect(fn() => new CanvasComposite('bad', $width, $height, $bounds, []))->toThrow(InvalidArgumentException::class);
  }
  $fill = new CanvasCompositeOperation(getCompositeTestFill());
  $one = new CanvasComposite('one', 16, 16, $bounds, [$fill]);
  expect(fn() => new CanvasComposite('bad', 1, 1, $bounds, [$fill]))->toThrow(InvalidArgumentException::class);
  expect(fn() => new PresentationCanvas(16, 16, composites: [$one, $one]))->toThrow(InvalidArgumentException::class);
  $items = array_map(fn($i) => new CanvasComposite('c' . $i, 16, 16, $bounds, []), range(0, 8));
  expect(fn() => new PresentationCanvas(16, 16, composites: $items))->toThrow(InvalidArgumentException::class);
  $items = array_map(fn($i) => new CanvasComposite('c' . $i, 2048, 2048, $bounds, []), range(0, 2));
  expect(fn() => new PresentationCanvas(16, 16, composites: $items))->toThrow(InvalidArgumentException::class);
  $items = array_map(fn($i) => new CanvasComposite('c' . $i, 16, 16, $bounds, array_fill(0, 129, $fill)), range(0, 1));
  expect(fn() => new PresentationCanvas(16, 16, composites: $items))->toThrow(InvalidArgumentException::class);
  $image = new CanvasCompositeOperation(['type' => 'image', 'asset' => 'x.png', 'destination' => getCompositeTestRect(),
    'displacement' => ['columns' => 64, 'rows' => 64, 'offsets' => array_fill(0, 4096, [0, 0])]]);
  expect(fn() => new PresentationCanvas(16, 16, composites: [new CanvasComposite('grid', 16, 16, $bounds, array_fill(0, 5, $image))]))
    ->toThrow(InvalidArgumentException::class);
  $expensive = new CanvasCompositeOperation(getCompositeTestFill(2048));
  expect(fn() => new PresentationCanvas(16, 16, composites: [new CanvasComposite('work', 2048, 2048, $bounds, array_fill(0, 16, $expensive))]))
    ->toThrow(InvalidArgumentException::class, 'cold raster work');
});

it('negotiates compositing before submission and keeps overlays above the entire background', function () {
  $effect = new CanvasComposite('effect', 16, 16, new CanvasRectangle(0, 0, 16, 16), [new CanvasCompositeOperation(getCompositeTestFill())], 7);
  $canvas = new PresentationCanvas(100, 100, composites: [$effect]);
  foreach ([['graphical_canvas'], ['graphical_canvas', 'canvas_compositing']] as $caps) {
    $transport = new FakeRendererTransport();
    $client = new RendererClient($transport);
    $client->start(new RendererSessionConfig('Effects', __DIR__, protocol: RendererProtocolVersion::V2, requiredCapabilities: $caps));
    $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => $caps]))];
    $client->pump();
    $presenter = new RendererPresentation($client, new RendererGridConfig(1, 1));
    if (count($caps) === 1) { expect(fn() => $presenter->presentCanvas($canvas))->toThrow(RendererProtocolException::class); }
    else { expect($presenter->presentCanvas($canvas))->toBeTrue(); }
  }
  $theme = new MenuPresentationCatalog(__DIR__, ['schema' => 'ichiloto.menu/1']);
  $overlay = new PresentationCanvas(100, 100, composites: [new CanvasComposite('overlay', 16, 16, new CanvasRectangle(0, 0, 16, 16), [])]);
  $combined = MenuCanvas::overlay($canvas, $overlay, $theme);
  expect(array_column($combined->composites, 'id'))->toBe(['effect', 'overlay'])
    ->and($combined->composites[1]->layer)->toBeGreaterThan(7);
  expect(fn() => new RendererSessionConfig('Effects', __DIR__, requiredCapabilities: ['canvas_compositing']))->toThrow(InvalidArgumentException::class);
});

it('preflights alpha and displaced PNG references rather than freezing their bytes or dimensions', function () {
  $root = __DIR__ . '/../Fixtures/Renderer';
  $image = new CanvasCompositeOperation(['type' => 'image', 'asset' => 'test-sprite.png', 'destination' => getCompositeTestRect(),
    'masks' => [['type' => 'image_alpha', 'asset' => 'test-sprite.png', 'destination' => getCompositeTestRect()]]]);
  $composite = new CanvasComposite('image', 16, 16, new CanvasRectangle(0, 0, 16, 16), [$image]);
  $result = CanvasImagePreflight::inspect([], $root, [$composite]);
  expect($result['sources'])->toHaveCount(1)->and($result['regions'])->toBe([]);
  expect(fn() => new CanvasCompositeOperation(['type' => 'image', 'asset' => '../invalid.png', 'destination' => getCompositeTestRect()]))
    ->toThrow(InvalidArgumentException::class);
});
