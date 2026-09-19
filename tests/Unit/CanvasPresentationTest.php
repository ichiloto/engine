<?php

declare(strict_types=1);

use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicator;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererStartupException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function canvasImage(string $id = 'one', int $layer = 0, ?SpriteSourceRect $crop = null): CanvasImage
{
  return new CanvasImage($id, 'synthetic.png', new CanvasRectangle(969.25, 265.5, 143, 181), $layer, $crop);
}

function canvasPresenter(array $capabilities, ?RendererGridConfig $grid = null): array
{
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Canvas', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: $capabilities));
  $transport->batches[] = [RendererEvent::fromJson(json_encode([
    'protocol' => 2, 'type' => 'ready', 'capabilities' => $capabilities,
  ], JSON_THROW_ON_ERROR))];
  $client->pump();
  return [new RendererPresentation($client, $grid ?? new RendererGridConfig(2, 1)), $transport];
}

it('submits free canvas geometry independently of terminal metrics and preserves fractions', function () {
  $canvas = new PresentationCanvas(1350, 720, [canvasImage()]);
  $messages = [];
  foreach ([new RendererGridConfig(2, 1, 16, 24), new RendererGridConfig(135, 36, 10, 20)] as $grid) {
    [$presenter, $transport] = canvasPresenter(['graphical_canvas'], $grid);
    expect($presenter->presentCanvas($canvas))->toBeTrue();
    $messages[] = $transport->sent[0]->encode();
  }
  expect($messages[0])->toBe($messages[1]);
  $wire = json_decode($messages[0], true, flags: JSON_THROW_ON_ERROR);
  expect($wire['canvas']['images'][0]['destination'])->toBe(['x' => 969.25, 'y' => 265.5, 'width' => 143, 'height' => 181])
    ->and($wire['textLayers'])->toBe([])->and($wire['sprites'])->toBe([])->and($wire['protocol'])->toBe(2);
});

it('rejects invalid canvas rectangle values without clamping or cell snapping', function ($values) {
  expect(fn() => new CanvasRectangle(...$values))->toThrow(InvalidArgumentException::class);
})->with([
  [[-1, 0, 1, 1]], [[0, -1, 1, 1]], [[0, 0, 0, 1]], [[0, 0, 1, -1]],
  [[NAN, 0, 1, 1]], [[0, INF, 1, 1]], [[0, 0, INF, 1]], [[0, 0, 1, NAN]],
  [[16384, 0, 1, 1]], [[0, 0, 1, 16385]], [[PHP_FLOAT_MAX, 0, PHP_FLOAT_MAX, 1]],
]);

it('validates canvas extents and completely contained image indicator and text rectangles', function () {
  foreach ([[0, 1], [1, 0], [-1, 1], [16385, 1], [1, 16385]] as [$width, $height]) {
    expect(fn() => new PresentationCanvas($width, $height))->toThrow(InvalidArgumentException::class);
  }
  expect(new PresentationCanvas(16384, 16384)->width)->toBe(16384);
  expect(fn() => new PresentationCanvas(100, 100, [canvasImage()]))->toThrow(InvalidArgumentException::class);
  $image = new CanvasImage('one', 'test.png', new CanvasRectangle(0, 0, 10, 10));
  $indicator = new CanvasIndicator('selected', 'one', CanvasIndicatorKind::OUTLINE,
    new CanvasRectangle(0, 0, 101, 10), 1, PresentationColor::ansi16(15));
  $text = new CanvasTextLayer('ui', 0, 1, 0, new RendererGridConfig(10, 1, 10, 20), []);
  expect(fn() => new PresentationCanvas(100, 100, [$image], [$indicator]))->toThrow(InvalidArgumentException::class);
  expect(fn() => new PresentationCanvas(100, 100, textLayers: [$text]))->toThrow(InvalidArgumentException::class);
  expect(fn() => new CanvasTextLayer('ui', 0, 0, 0, new RendererGridConfig(2, 1), [new PresentationTextRun(0, 1, 'ab')]))
    ->toThrow(InvalidArgumentException::class);
});

it('keeps opacity paths identity layer and indicator stroke inside the contract', function () {
  $rect = new CanvasRectangle(0, 0, 20, 20);
  foreach (['', str_repeat('a', 257), "\n", "\xff"] as $id) {
    expect(fn() => new CanvasImage($id, 'a.png', $rect))->toThrow(InvalidArgumentException::class);
    expect(fn() => new CanvasTextLayer($id, 0, 0, 0, new RendererGridConfig(1, 1), []))->toThrow(InvalidArgumentException::class);
  }
  foreach (['../a.png', '/a.png', 'C:/a.png', 'a\\b.png', str_repeat('a', 4097), "a\0.png"] as $asset) {
    expect(fn() => new CanvasImage('id', $asset, $rect))->toThrow(InvalidArgumentException::class);
  }
  foreach ([-1, 1.01, NAN, INF] as $opacity) {
    expect(fn() => new CanvasImage('id', 'a.png', $rect, opacity: $opacity))->toThrow(InvalidArgumentException::class);
  }
  foreach ([0, 17] as $stroke) {
    expect(fn() => new CanvasIndicator('i', 'id', CanvasIndicatorKind::OUTLINE, $rect, $stroke, PresentationColor::rgb(1, 2, 3)))
      ->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new CanvasIndicator('i', 'id', CanvasIndicatorKind::OUTLINE,
    new CanvasRectangle(0, 0, 1.5, 2), 2, PresentationColor::ansi16(15)))->toThrow(InvalidArgumentException::class);
  expect(fn() => canvasImage(layer: 2147483648))->toThrow(InvalidArgumentException::class);
  expect(new CanvasImage('id', 'a.png', $rect, opacity: 0)->toArray()['opacity'])->toBe(0.0)
    ->and(new CanvasImage('id', 'a.png', $rect)->toArray())->not->toHaveKey('opacity');
});

it('rejects wrong typed geometry inputs at strict PHP construction boundaries', function () {
  foreach (['1', true, null, []] as $bad) {
    expect(fn() => new CanvasRectangle($bad, 0, 1, 1))->toThrow(TypeError::class);
  }
  expect(fn() => new PresentationCanvas(1.5, 1))->toThrow(TypeError::class);
  expect(fn() => new CanvasImage('id', 'a.png', new CanvasRectangle(0, 0, 1, 1), opacity: null))->toThrow(TypeError::class);
});

it('preserves stable instance references and equal-layer order through replacement and removal', function () {
  $first = canvasImage('enemy-1');
  $second = canvasImage('enemy-2');
  $selection = new CanvasIndicator('selected', 'enemy-2', CanvasIndicatorKind::OUTLINE,
    $second->destination, 2, PresentationColor::ansi16(15), 10);
  $acting = new CanvasIndicator('acting', 'enemy-2', CanvasIndicatorKind::UNDERLINE,
    $second->destination, 2, PresentationColor::ansi16(14), 10);
  $canvas = new PresentationCanvas(1350, 720, [$second, $first], [$selection, $acting]);
  expect(array_column($canvas->images, 'id'))->toBe(['enemy-2', 'enemy-1'])
    ->and(array_column($canvas->indicators, 'id'))->toBe(['selected', 'acting']);
  expect(new PresentationCanvas(1350, 720, [$second], [$selection])->indicators[0]->imageId)->toBe('enemy-2');
  expect(fn() => new PresentationCanvas(1350, 720, [$first], [$selection]))->toThrow(InvalidArgumentException::class);
  expect(fn() => new PresentationCanvas(1350, 720, [$first, $first]))->toThrow(InvalidArgumentException::class);
  expect(fn() => new PresentationCanvas(1350, 720, [$second], [$selection, $selection]))->toThrow(InvalidArgumentException::class);
});

it('detaches list references and keeps explicit opaque UI separate from transparent feedback', function () {
  $run = new PresentationTextRun(0, 0, ' ', background: PresentationColor::rgb(17, 24, 32));
  $text = new CanvasTextLayer('ui', 10, 0, 0, new RendererGridConfig(2, 1),
    [&$run, new PresentationTextRun(0, 1, '9')]);
  $image = canvasImage();
  $canvas = new PresentationCanvas(1350, 720, [&$image], textLayers: [&$text]);
  $image = canvasImage('replacement');
  $run = new PresentationTextRun(0, 0, 'x');
  $text = new CanvasTextLayer('replacement', 1, 0, 0, new RendererGridConfig(1, 1), []);
  $wire = $canvas->toArray();
  expect($canvas->images[0]->id)->toBe('one')->and($wire['textLayers'][0]['id'])->toBe('ui')
    ->and($wire['textLayers'][0]['runs'][0]['text'])->toBe(' ')
    ->and($wire['textLayers'][0]['runs'][0]['background'])->toBe(['kind' => 'rgb', 'r' => 17, 'g' => 24, 'b' => 32])
    ->and($wire['textLayers'][0]['runs'][1]['background'])->toBeNull();
});

it('bounds typed collections and aggregate text costs', function () {
  $images = array_map(fn($i) => canvasImage((string)$i), range(0, 1023));
  $indicators = array_map(fn($i) => new CanvasIndicator((string)$i, '0', CanvasIndicatorKind::OUTLINE,
    $images[0]->destination, 1, PresentationColor::ansi16(15)), range(0, 2047));
  $text = array_map(fn($i) => new CanvasTextLayer((string)$i, 0, 0, 0, new RendererGridConfig(1, 1), []), range(0, 63));
  expect(new PresentationCanvas(1350, 720, $images, $indicators, $text)->images)->toHaveCount(1024);
  foreach ([['images' => [...$images, canvasImage('extra')]], ['indicators' => [...$indicators, $indicators[0]]],
    ['textLayers' => [...$text, $text[0]]], ['images' => ['named' => $images[0]]], ['images' => [new stdClass()]]] as $args) {
    expect(fn() => new PresentationCanvas(1350, 720, ...$args))->toThrow(InvalidArgumentException::class);
  }
  $runs = array_fill(0, 32768, new PresentationTextRun(0, 0, ''));
  $layer = fn(array $r) => new CanvasTextLayer('ui', 0, 0, 0, new RendererGridConfig(512, 1, 1, 1), $r);
  expect(new PresentationCanvas(1350, 720, textLayers: [$layer($runs)])->textLayers[0]->runs)->toHaveCount(32768);
  expect(fn() => new PresentationCanvas(1350, 720, textLayers: [$layer([...$runs, $runs[0]])]))->toThrow(InvalidArgumentException::class);
  $runs = array_fill(0, 1024, new PresentationTextRun(0, 0, str_repeat('x', 512)));
  expect(new PresentationCanvas(1350, 720, textLayers: [$layer($runs)])->textLayers)->toHaveCount(1);
  expect(fn() => new PresentationCanvas(1350, 720, textLayers: [$layer([...$runs, new PresentationTextRun(0, 0, 'x')])]))
    ->toThrow(InvalidArgumentException::class);
});

it('rejects mixed legacy and canvas frames without changing legacy wire shapes', function () {
  $canvas = new PresentationCanvas(1350, 720);
  expect(fn() => new StyledPresentationFrame(1, [new PresentationTextLayer('world', 0, [])], canvas: $canvas))
    ->toThrow(InvalidArgumentException::class);
  expect(fn() => new StyledPresentationFrame(1, sprites: [new PresentationSprite('p', 'p.png', 0, 0, 1, 1)], canvas: $canvas))
    ->toThrow(InvalidArgumentException::class);
  expect(fn() => new StyledPresentationFrame(1, tileBatches: [new stdClass()], canvas: $canvas))->toThrow(InvalidArgumentException::class);
  expect(new StyledPresentationFrame(1)->toRendererMessage()->payload)->toBe(['frame' => 1, 'textLayers' => [], 'sprites' => []]);
});

it('requires v2 requested and acknowledged canvas and crop capabilities even for blank canvases', function () {
  expect(fn() => new RendererSessionConfig('v1', sys_get_temp_dir(), requiredCapabilities: ['graphical_canvas']))
    ->toThrow(InvalidArgumentException::class);
  [$presenter, $transport] = canvasPresenter([]);
  expect(fn() => $presenter->presentCanvas(new PresentationCanvas(1, 1)))->toThrow(RendererProtocolException::class, 'graphical_canvas');
  expect($transport->sent)->toBe([]);
  [$presenter, $transport] = canvasPresenter(['graphical_canvas']);
  $cropped = new PresentationCanvas(1350, 720, [canvasImage(crop: new SpriteSourceRect(0, 0, 1, 1))]);
  expect(fn() => $presenter->presentCanvas($cropped))->toThrow(RendererProtocolException::class, 'sprite_source_rect');
  expect($transport->sent)->toBe([]);
  [$presenter, $transport] = canvasPresenter(['graphical_canvas', 'sprite_source_rect']);
  expect($presenter->presentCanvas($cropped))->toBeTrue();
});

it('negotiates the new capability over real process pipes and rejects old renderers', function ($scenario) {
  $transport = new ProcessRendererTransport(new RendererProcessConfig([
    PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', $scenario,
  ]));
  $client = new RendererClient($transport);
  $session = new RendererSessionConfig('Canvas', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: ['graphical_canvas']);
  try {
    if ($scenario === 'normal') {
      expect(fn() => $client->start($session))->toThrow(RendererStartupException::class, 'did not acknowledge');
    } else {
      $client->start($session);
      $client->pump();
      expect($client->supports('graphical_canvas'))->toBeTrue();
      expect(new RendererPresentation($client, new RendererGridConfig())->presentCanvas(new PresentationCanvas(1, 1)))->toBeTrue();
    }
  } finally { $transport->shutdown(); }
})->with(['normal', 'capabilities']);

it('shares transactional deduplication and sequencing across canvas and field transitions', function () {
  [$presenter, $transport] = canvasPresenter(['graphical_canvas']);
  $canvas = new PresentationCanvas(1350, 720, [canvasImage()]);
  $field = new ConsolePresentationSnapshot(2, 1, []);
  expect($presenter->present($field))->toBeTrue()->and($presenter->presentCanvas($canvas))->toBeTrue()
    ->and($presenter->presentCanvas($canvas))->toBeFalse();
  $transport->sendFailure = new RendererTransportException('backpressure');
  expect(fn() => $presenter->presentCanvas(new PresentationCanvas(1350, 720)))->toThrow(RendererTransportException::class);
  expect($presenter->presentCanvas($canvas))->toBeFalse();
  $transport->sendFailure = null;
  expect($presenter->presentCanvas(new PresentationCanvas(1350, 720)))->toBeTrue()
    ->and($presenter->present($field))->toBeTrue()->and($presenter->present($field))->toBeFalse();
  expect(array_map(fn($m) => $m->payload['frame'], $transport->sent))->toBe([1, 2, 3, 4])
    ->and($transport->sent[2]->payload['canvas']['images'])->toBe([])
    ->and($transport->sent[3]->payload)->not->toHaveKey('canvas');
});
