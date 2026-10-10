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
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

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

it('clips an anchored image at every edge without translating or stretching its remaining pixels', function (bool $flipX, bool $flipY) {
  $source = new SpriteSourceRect(100, 200, 40, 30);
  $image = CanvasImage::createClipped('clipped', 'synthetic.png', -10, -20, 80, 60, 50, 30, 100, $source, $flipX, $flipY);
  expect($image->destination->toArray())->toBe(['x' => 0.0, 'y' => 0.0, 'width' => 50.0, 'height' => 30.0])
    ->and($image->sourceRect->toArray())->toBe(['x' => $flipX ? 110 : 105, 'y' => $flipY ? 205 : 210,
      'width' => 25, 'height' => 15])
    ->and($image->flipX)->toBe($flipX)->and($image->flipY)->toBe($flipY);
  new PresentationCanvas(50, 30, [$image]);
  expect(CanvasImage::createClipped('outside', 'synthetic.png', 50, 0, 80, 60, 50, 30, 100, $source))->toBeNull();
  $fractional = CanvasImage::createClipped('fractional', 'synthetic.png', -10.5, -20.5, 80, 60, 50, 30, 100, $source);
  expect($fractional->destination->x)->toBe(1.5)->and($fractional->destination->y)->toBe(1.5)
    ->and($fractional->destination->width / $fractional->sourceRect->width)->toBe(2.0)
    ->and($fractional->destination->height / $fractional->sourceRect->height)->toBe(2.0);
  new PresentationCanvas(50, 30, [$fractional]);
})->with([false, true])->with([false, true]);

it('constructs strictly contained fractional clipping edges without changing source pixels or flips', function (bool $flipX, bool $flipY) {
  $source = new SpriteSourceRect(64, 96, 2048, 1536);
  $image = CanvasImage::createClipped('fractional-edge', 'replaceable-sheet.png',
    -104.80000000000018, -74.40000000000009, 1638.4, 1228.8000000000002, 1280, 720, 7, $source, $flipX, $flipY);
  expect($image)->not->toBeNull()
    ->and($image->sourceRect->toArray())->toBe(['x' => $flipX ? 381 : 196, 'y' => $flipY ? 639 : 190,
      'width' => 1599, 'height' => 899])
    ->and($image->destination->x)->toBe(0.7999999999998266)
    ->and($image->destination->y)->toBe(0.7999999999999261)
    ->and($image->destination->x + $image->destination->width)->toBeLessThanOrEqual(1280)
    ->and($image->destination->y + $image->destination->height)->toBeLessThanOrEqual(720)
    ->and($image->destination->width / 1599)->toEqualWithDelta(.8, 1e-15)
    ->and($image->destination->height / 899)->toEqualWithDelta(.8, 1e-15)
    ->and($image->flipX)->toBe($flipX)->and($image->flipY)->toBe($flipY)->and($image->layer)->toBe(7);
  new PresentationCanvas(1280, 720, [$image]);
  $wire = json_decode(json_encode($image->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
  new CanvasRectangle(...array_values($wire['destination']))->assertWithin(1280, 720);
})->with([false, true])->with([false, true]);

it('keeps zoomed partial source-sheet placements bounded at both mirrored edges', function (bool $flipX, bool $flipY) {
  $source = new SpriteSourceRect(4294965247, 4294965759, 2048, 1536);
  foreach ([.125, .3, .8, 1.1, 1.5, 2.75] as $scale) {
    foreach ([[-.2, -.7], [73.3, 45.9], [319.8, 179.7], [-700.3, -500.9]] as [$x, $y]) {
      $image = CanvasImage::createClipped('zoomed', 'replaceable-sheet.png', $x, $y,
        2048 * $scale, 1536 * $scale, 320, 180, 0, $source, $flipX, $flipY);
      if ($image === null) { continue; }
      new PresentationCanvas(320, 180, [$image]);
      $crop = $image->sourceRect;
      expect($crop->x)->toBeGreaterThanOrEqual($source->x)
        ->and($crop->y)->toBeGreaterThanOrEqual($source->y)
        ->and($crop->x + $crop->width)->toBeLessThanOrEqual(4294967295)
        ->and($crop->y + $crop->height)->toBeLessThanOrEqual(4294967295)
        ->and($image->destination->width / $crop->width)->toEqualWithDelta($scale, 1e-12)
        ->and($image->destination->height / $crop->height)->toEqualWithDelta($scale, 1e-12);
      foreach ($image->destination->toArray() as $value) { expect(is_finite($value))->toBeTrue(); }
      $left = $flipX ? $source->x + $source->width - $crop->x - $crop->width : $crop->x - $source->x;
      $top = $flipY ? $source->y + $source->height - $crop->y - $crop->height : $crop->y - $source->y;
      expect($image->destination->x)->toEqualWithDelta($x + $left * $scale, 1e-12)
        ->and($image->destination->y)->toEqualWithDelta($y + $top * $scale, 1e-12);
    }
  }
})->with([false, true])->with([false, true]);

it('omits fully offscreen and subpixel remnants while retaining strict placement limits', function () {
  $source = new SpriteSourceRect(0, 0, 10, 10);
  foreach ([[100, 0, 10, 10], [0, 100, 10, 10], [-10, 0, 10, 10], [0, -10, 10, 10],
    [99.9, 0, 10, 10], [0, 99.9, 10, 10], [PHP_FLOAT_MAX, 0, 10, 10], [-PHP_FLOAT_MAX, 0, 10, 10]] as $placement) {
    expect(CanvasImage::createClipped('outside', 'synthetic.png', ...[...$placement, 100, 100, 0, $source]))->toBeNull();
  }
  foreach ([[NAN, 0, 10, 10, 100, 100], [0, INF, 10, 10, 100, 100], [0, 0, INF, 10, 100, 100],
    [0, 0, 10, NAN, 100, 100], [0, 0, 0, 10, 100, 100], [0, 0, 10, -1, 100, 100],
    [0, 0, 16385, 10, 100, 100], [0, 0, 10, 10, 0, 100], [0, 0, 10, 10, 100, 16385]] as $placement) {
    expect(fn() => CanvasImage::createClipped('invalid', 'synthetic.png', ...[...$placement, 0, $source]))
      ->toThrow(InvalidArgumentException::class);
  }
  $image = CanvasImage::createClipped('limit', 'synthetic.png', 0, 0, 16384, 16384, 16384, 16384, 0,
    new SpriteSourceRect(0, 0, 4294967295, 4294967295));
  new PresentationCanvas(16384, 16384, [$image]);
  expect($image->sourceRect->width)->toBe(4294967295);
  $overflow = new CanvasRectangle(0.7999999999998266, 0.7999999999999261, 1279.2, 719.2000000000002);
  expect(fn() => $overflow->assertWithin(1280, 720))->toThrow(InvalidArgumentException::class);
  expect(fn() => new PresentationCanvas(1280, 720, [new CanvasImage('overflow', 'synthetic.png', $overflow)]))
    ->toThrow(InvalidArgumentException::class);
});

it('namespaces stacked artwork and indicator references without changing geometry or owner identity', function () {
  $image = new CanvasImage('actor', 'synthetic.png', new CanvasRectangle(0, 0, 10, 20), 4, flipX: true);
  $indicator = new CanvasIndicator('focus', 'actor', CanvasIndicatorKind::OUTLINE,
    new CanvasRectangle(0, 0, 10, 20), 1, PresentationColor::rgb(255, 255, 255), layer: 5);
  $overlay = new PresentationCanvas(100, 100, [$image], [$indicator], presentationOwners: ['ui:123']);
  $canvas = PresentationCanvas::composeOverlay(new PresentationCanvas(100, 100, [$image]), $overlay, 'upper-');
  expect(array_column($canvas->images, 'id'))->toBe(['actor', 'upper-actor'])
    ->and($canvas->indicators[0]->id)->toBe('upper-focus')
    ->and($canvas->indicators[0]->imageId)->toBe('upper-actor')
    ->and($canvas->images[1]->destination)->toEqual($image->destination)
    ->and($canvas->images[1]->flipX)->toBeTrue()
    ->and($canvas->presentationOwners)->toBe(['ui:123'])
    ->and($canvas->toArray())->not->toHaveKey('presentationOwners');
});

it('submits free canvas geometry independently of terminal metrics and preserves fractions', function () {
  $canvas = new PresentationCanvas(1350, 720, [canvasImage()]);
  $messages = [];
  $frames = [];
  foreach ([new RendererGridConfig(2, 1, 16, 24), new RendererGridConfig(135, 36, 10, 20)] as $grid) {
    [$presenter, $transport] = canvasPresenter(['graphical_canvas'], $grid);
    expect($presenter->presentCanvas($canvas))->toBeTrue();
    $messages[] = $transport->sent[0]->encode();
    $frames[] = RetainedFrameState::replay($transport->sent)[0];
  }
  expect($messages[0])->toBe($messages[1]);
  $wire = json_decode($messages[0], true, flags: JSON_THROW_ON_ERROR);
  expect($frames[0])->toBe($frames[1])
    ->and($frames[0]['canvas']['images'][0]['destination'])->toBe(['x' => 969.25, 'y' => 265.5, 'width' => 143.0, 'height' => 181.0])
    ->and($frames[0]['textLayers'])->toBe([])->and($frames[0]['sprites'])->toBe([])->and($wire['protocol'])->toBe(2)
    ->and($wire)->not->toHaveKeys(['canvas', 'textLayers', 'sprites']);
});

it('rejects invalid canvas rectangle values without clamping or cell snapping', function ($values) {
  expect(fn() => new CanvasRectangle(...$values))->toThrow(InvalidArgumentException::class);
})->with([
  [[-1, 0, 1, 1]], [[0, -1, 1, 1]], [[0, 0, 0, 1]], [[0, 0, 1, -1]],
  [[NAN, 0, 1, 1]], [[0, INF, 1, 1]], [[0, 0, INF, 1]], [[0, 0, 1, NAN]],
  [[16384, 0, 1, 1]], [[0, 0, 1, 16385]], [[PHP_FLOAT_MAX, 0, PHP_FLOAT_MAX, 1]],
]);

it('validates and detaches PHP overlay protection without changing the renderer wire contract', function () {
  $area = new CanvasRectangle(10, 10, 20, 20);
  $areas = [&$area];
  $canvas = new PresentationCanvas(100, 100, protectedAreas: $areas);
  $area = new CanvasRectangle(0, 0, 100, 100);
  expect($canvas->protectedAreas[0]->width)->toBe(20.0)
    ->and($canvas->toArray())->toBe(new PresentationCanvas(100, 100)->toArray());
  foreach ([[new CanvasRectangle(90, 0, 20, 10)], ['not a rectangle'], ['key' => $area], array_fill(0, 32769, $area)] as $invalid) {
    expect(fn() => new PresentationCanvas(100, 100, protectedAreas: $invalid))->toThrow(InvalidArgumentException::class);
  }
});

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

it('validates optional image brightness without changing legacy opacity or wire defaults', function () {
  $rect = new CanvasRectangle(0, 0, 20, 20);
  foreach ([-0.01, 1.01, NAN, INF, -INF] as $brightness) {
    expect(fn() => new CanvasImage('image', 'a.png', $rect, brightness: $brightness))->toThrow(InvalidArgumentException::class);
  }
  foreach ([null, '0.6', true, []] as $brightness) {
    expect(fn() => new CanvasImage('image', 'a.png', $rect, brightness: $brightness))->toThrow(TypeError::class);
  }
  expect(new CanvasImage('image', 'a.png', $rect)->toArray())->not->toHaveKey('brightness');
  expect(new CanvasImage('image', 'a.png', $rect, brightness: 0)->toArray()['brightness'])->toBe(0.0);
  expect(new CanvasImage('image', 'a.png', $rect, brightness: 0.6)->opacity)->toBe(1.0);
});

it('negotiates image tone and updates retained brightness without resetting unrelated canvas entities', function () {
  expect(fn() => new RendererSessionConfig('Tone', sys_get_temp_dir(), requiredCapabilities: ['canvas_image_tone']))
    ->toThrow(InvalidArgumentException::class);
  expect(fn() => new RendererSessionConfig('Tone', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: ['canvas_image_tone']))->toThrow(InvalidArgumentException::class);
  $rect = new CanvasRectangle(20, 20, 40, 60);
  $make = fn($brightness) => new PresentationCanvas(100, 100, [
    new CanvasImage('bust', 'synthetic.png', $rect, brightness: $brightness),
    new CanvasImage('other', 'synthetic.png', new CanvasRectangle(0, 0, 10, 10)),
  ]);
  [$legacy, $oldPeer] = canvasPresenter(['graphical_canvas']);
  expect(fn() => $legacy->presentCanvas($make(0.6)))->toThrow(RendererProtocolException::class, 'canvas_image_tone');
  expect($oldPeer->sent)->toBe([])->and($legacy->presentCanvas($make(1)))->toBeTrue();
  [$presenter, $peer] = canvasPresenter(['graphical_canvas', 'canvas_image_tone']);
  expect($presenter->presentCanvas($make(0.6)))->toBeTrue()
    ->and($presenter->presentCanvas($make(0.6)))->toBeFalse()
    ->and($presenter->presentCanvas($make(1)))->toBeTrue();
  expect($peer->sent[1]->payload['reset'])->toBeFalse()
    ->and($peer->sent[1]->payload['operations'])->toHaveCount(1)
    ->and($peer->sent[1]->payload['operations'][0]['id'])->toBe('bust');
  $frames = RetainedFrameState::replay($peer->sent);
  expect($frames[0]['canvas']['images'][0]['brightness'])->toBe(0.6)
    ->and($frames[1]['canvas']['images'][0])->not->toHaveKey('brightness')
    ->and($frames[1]['canvas']['images'][1])->toEqual($frames[0]['canvas']['images'][1]);
});

it('negotiates independent image flips and removes them on retained replacement', function () {
  $rect = new CanvasRectangle(20, 20, 40, 60);
  $make = fn(bool $flip) => new PresentationCanvas(100, 100, [
    new CanvasImage('stroke', 'synthetic.png', $rect, flipX: $flip, flipY: $flip),
  ]);
  expect(fn() => new RendererSessionConfig('Flip', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: ['canvas_image_flip']))->toThrow(InvalidArgumentException::class);
  [$legacy, $oldPeer] = canvasPresenter(['graphical_canvas']);
  expect(fn() => $legacy->presentCanvas($make(true)))->toThrow(RendererProtocolException::class, 'canvas_image_flip');
  expect($oldPeer->sent)->toBeEmpty()->and($legacy->presentCanvas($make(false)))->toBeTrue();
  [$presenter, $peer] = canvasPresenter(['graphical_canvas', 'canvas_image_flip']);
  expect($presenter->presentCanvas($make(true)))->toBeTrue()
    ->and($presenter->presentCanvas($make(true)))->toBeFalse()
    ->and($presenter->presentCanvas($make(false)))->toBeTrue();
  $frames = RetainedFrameState::replay($peer->sent);
  expect($frames[0]['canvas']['images'][0])->toMatchArray(['flipX' => true, 'flipY' => true])
    ->and($frames[1]['canvas']['images'][0])->not->toHaveKeys(['flipX', 'flipY'])
    ->and($peer->sent[1]->payload['reset'])->toBeFalse();
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
  expect(fn() => $presenter->presentCanvas($canvas))->toThrow(RendererTransportException::class);
  $transport->sendFailure = null;
  expect($presenter->presentCanvas(new PresentationCanvas(1350, 720)))->toBeTrue()
    ->and($presenter->present($field))->toBeTrue()->and($presenter->present($field))->toBeFalse();
  $frames = RetainedFrameState::replay($transport->sent);
  expect(array_map(fn($m) => $m->payload['frame'], $transport->sent))->toBe([1, 2, 3, 4])
    ->and($transport->sent[2]->payload['reset'])->toBeTrue()
    ->and($frames[2]['canvas']['images'])->toBe([])
    ->and($frames[3])->not->toHaveKey('canvas')
    ->and($transport->sent[3]->payload['operations'])->toContain(['op' => 'remove', 'kind' => 'canvas', 'id' => 'canvas']);
});
