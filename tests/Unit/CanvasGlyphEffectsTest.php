<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGlyphEffects;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function glyphEffectsTestArguments(array $overrides = []): array
{
  return array_replace([
    'outlineWidth' => 0.75, 'outlineColor' => PresentationColor::ansi16(0),
    'shadowOffsetX' => 1.25, 'shadowOffsetY' => -2.5, 'shadowSigma' => 0.5,
    'shadowOpacity' => 0.65, 'shadowColor' => PresentationColor::rgb(1, 2, 3),
  ], $overrides);
}

/** Separate requested and acknowledged capabilities; no real renderer process. */
function glyphEffectsTestPresenter(array $requested, ?array $acknowledged): array
{
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Glyph effects', sys_get_temp_dir(),
    protocol: RendererProtocolVersion::V2, requiredCapabilities: $requested));
  if ($acknowledged !== null) {
    $transport->batches[] = [RendererEvent::fromJson(json_encode([
      'protocol' => 2, 'type' => 'ready', 'capabilities' => $acknowledged,
    ], JSON_THROW_ON_ERROR))];
    $client->pump();
  }
  return [new RendererPresentation($client, new RendererGridConfig(1, 1)), $transport, $client];
}

function glyphEffectsTestCanvas(?CanvasGlyphEffects $effects, string $label = 'A', bool $clip = false): PresentationCanvas
{
  return new PresentationCanvas(64, 64, textLayers: [
    new CanvasTextLayer('label', 10, 24, 24, new RendererGridConfig(1, 1, 8, 8),
      [new PresentationTextRun(0, 0, $label)],
      clipRect: $clip ? new CanvasRectangle(24, 24, 4, 4) : null, glyphEffects: $effects),
  ]);
}

it('rejects non-finite values for every numeric glyph effect parameter', function (string $field, float $value) {
  expect(fn() => new CanvasGlyphEffects(...glyphEffectsTestArguments([$field => $value])))
    ->toThrow(InvalidArgumentException::class, 'finite');
})->with(['outlineWidth', 'shadowOffsetX', 'shadowOffsetY', 'shadowSigma', 'shadowOpacity'])
  ->with(['NaN' => NAN, 'positive infinity' => INF, 'negative infinity' => -INF]);

it('accepts exact glyph limits and rejects finite values just outside them', function (string $field, float $low, float $high) {
  expect(new CanvasGlyphEffects(...glyphEffectsTestArguments([$field => $low]))->$field)->toBe($low)
    ->and(new CanvasGlyphEffects(...glyphEffectsTestArguments([$field => $high]))->$field)->toBe($high);
  foreach ([$low - 0.000001, $high + 0.000001, -PHP_FLOAT_MAX, PHP_FLOAT_MAX] as $value) {
    expect(fn() => new CanvasGlyphEffects(...glyphEffectsTestArguments([$field => $value])))
      ->toThrow(InvalidArgumentException::class, 'bounded');
  }
})->with([
  ['outlineWidth', 0.0, 4.0], ['shadowOffsetX', -8.0, 8.0], ['shadowOffsetY', -8.0, 8.0],
  ['shadowSigma', 0.0, 4.0], ['shadowOpacity', 0.0, 1.0],
]);

it('rejects coercible and wrong types at strict numeric glyph boundaries', function (string $field, mixed $value) {
  expect(fn() => new CanvasGlyphEffects(...glyphEffectsTestArguments([$field => $value])))
    ->toThrow(TypeError::class);
})->with(['outlineWidth', 'shadowOffsetX', 'shadowOffsetY', 'shadowSigma', 'shadowOpacity'])
  ->with(['numeric string' => '1', 'boolean' => true, 'null' => null, 'array' => [[]], 'object' => new stdClass()]);

it('requires typed outline and shadow colors', function (string $field, mixed $value) {
  expect(fn() => new CanvasGlyphEffects(...glyphEffectsTestArguments([$field => $value])))
    ->toThrow(TypeError::class);
})->with(['outlineColor', 'shadowColor'])
  ->with(['string' => '#000000', 'index' => 0, 'null' => null, 'array' => [['kind' => 'ansi16', 'index' => 0]],
    'object' => new stdClass()]);

it('accepts integer numeric arguments without losing readonly typed state', function () {
  $effects = new CanvasGlyphEffects(4, PresentationColor::ansi16(0), -8, 8, 4, 1, PresentationColor::ansi256(16));
  expect([$effects->outlineWidth, $effects->shadowOffsetX, $effects->shadowOffsetY, $effects->shadowSigma, $effects->shadowOpacity])
    ->toBe([4.0, -8.0, 8.0, 4.0, 1.0]);
  expect(fn() => $effects->outlineWidth = 1.0)->toThrow(Error::class);
});

it('rounds fractional contour and three-sigma shadow padding outward on the signed sides', function (array $overrides, array $expected) {
  $effects = new CanvasGlyphEffects(...glyphEffectsTestArguments($overrides));
  $before = $effects->toArray();
  expect($effects->padding())->toBe($expected)
    ->and($effects->padding())->toBe($expected)
    ->and($effects->toArray())->toBe($before);
})->with([
  'fractional positive x negative y' => [[], ['left' => 3, 'top' => 6, 'right' => 4, 'bottom' => 3]],
  'mirrored offsets' => [['shadowOffsetX' => -1.25, 'shadowOffsetY' => 2.5],
    ['left' => 4, 'top' => 3, 'right' => 3, 'bottom' => 6]],
  'zero effects' => [['outlineWidth' => 0, 'shadowOffsetX' => 0, 'shadowOffsetY' => 0, 'shadowSigma' => 0],
    ['left' => 0, 'top' => 0, 'right' => 0, 'bottom' => 0]],
  'fractional outline only' => [['outlineWidth' => 0.25, 'shadowOffsetX' => 0, 'shadowOffsetY' => 0, 'shadowSigma' => 0],
    ['left' => 1, 'top' => 1, 'right' => 1, 'bottom' => 1]],
  'tiny nonzero blur retains kernel radius' => [['outlineWidth' => 0.25, 'shadowOffsetX' => 0, 'shadowOffsetY' => 0, 'shadowSigma' => 0.01],
    ['left' => 2, 'top' => 2, 'right' => 2, 'bottom' => 2]],
  'maximum signed extents' => [['outlineWidth' => 4, 'shadowOffsetX' => -8, 'shadowOffsetY' => 8, 'shadowSigma' => 4],
    ['left' => 24, 'top' => 16, 'right' => 16, 'bottom' => 24]],
  'transparent shadow preserves declared geometry' => [['shadowOpacity' => 0],
    ['left' => 3, 'top' => 6, 'right' => 4, 'bottom' => 3]],
]);

it('expands only paint bounds while preserving fractional origin grid and local run positions', function () {
  $grid = new RendererGridConfig(3, 2, 10, 20);
  $run = new PresentationTextRun(1, 2, 'Z');
  $effects = new CanvasGlyphEffects(...glyphEffectsTestArguments());
  $text = new CanvasTextLayer('label', 7, 32.25, 40.5, $grid, [$run], glyphEffects: $effects);
  expect($text->grid)->toBe($grid)
    ->and($text->runs)->toBe([$run])
    ->and($text->bounds->toArray())->toBe(['x' => 32.25, 'y' => 40.5, 'width' => 30.0, 'height' => 40.0])
    ->and($text->paintBounds->toArray())->toBe(['x' => 29.25, 'y' => 34.5, 'width' => 37.0, 'height' => 49.0])
    ->and($text->toArray()['origin'])->toBe(['x' => 32.25, 'y' => 40.5])
    ->and($text->toArray()['grid'])->toBe(['columns' => 3, 'rows' => 2, 'cellWidth' => 10, 'cellHeight' => 20]);
  expect(fn() => new CanvasTextLayer('overflow', 7, 32.25, 40.5, $grid,
    [new PresentationTextRun(1, 2, 'ZZ')], glyphEffects: $effects))->toThrow(InvalidArgumentException::class);
  expect(fn() => new CanvasTextLayer('overflow', 7, 32.25, 40.5, $grid,
    [new PresentationTextRun(2, 0, 'Z')], glyphEffects: $effects))->toThrow(InvalidArgumentException::class);
});

it('requires the entire expanded paint rectangle to fit even with a tiny clip or zero opacity', function (float $opacity) {
  $effects = new CanvasGlyphEffects(1, PresentationColor::ansi16(0), -2, -3, 1, 0.5, PresentationColor::ansi16(0));
  $text = new CanvasTextLayer('label', 1, 8, 8, new RendererGridConfig(1, 1, 8, 8),
    [new PresentationTextRun(0, 0, 'A')], new CanvasRectangle(8, 8, 1, 1), $opacity, $effects);
  expect($text->paintBounds->toArray())->toBe(['x' => 2.0, 'y' => 1.0, 'width' => 18.0, 'height' => 19.0])
    ->and(new PresentationCanvas(20, 20, textLayers: [$text])->textLayers)->toBe([$text]);
  foreach ([[19, 20], [20, 19]] as [$width, $height]) {
    expect(fn() => new PresentationCanvas($width, $height, textLayers: [$text]))
      ->toThrow(InvalidArgumentException::class, 'fit completely');
  }
  foreach ([[5.75, 8], [8, 6.75]] as [$x, $y]) {
    expect(fn() => new CanvasTextLayer('negative-paint-origin', 1, $x, $y, new RendererGridConfig(1, 1, 8, 8),
      [], new CanvasRectangle(8, 8, 1, 1), $opacity, $effects))->toThrow(InvalidArgumentException::class);
  }
})->with([0.0, 1.0]);

it('omits absent glyph effects and serializes explicit effects without expanding the wire grid', function () {
  $plain = glyphEffectsTestCanvas(null)->textLayers[0];
  expect($plain->paintBounds)->toEqual($plain->bounds)
    ->and($plain->toArray())->not->toHaveKeys(['glyphEffects', 'paintBounds', 'clipRect', 'opacity']);
  $effects = new CanvasGlyphEffects(...glyphEffectsTestArguments());
  $canvas = glyphEffectsTestCanvas($effects);
  $wire = $canvas->toArray()['textLayers'][0];
  expect($wire['glyphEffects'])->toBe([
    'outline' => ['width' => 0.75, 'color' => ['kind' => 'ansi16', 'index' => 0]],
    'shadow' => ['offsetX' => 1.25, 'offsetY' => -2.5, 'sigma' => 0.5, 'opacity' => 0.65,
      'color' => ['kind' => 'rgb', 'r' => 1, 'g' => 2, 'b' => 3]],
  ])->and($wire['grid'])->toBe($plain->toArray()['grid'])
    ->and($wire['origin'])->toBe($plain->toArray()['origin'])
    ->and($wire['runs'])->toBe($plain->toArray()['runs'])
    ->and($wire)->not->toHaveKey('paintBounds');
});

it('requires both protocol v2 and graphical canvas for glyph effects capability', function () {
  $canvas = RendererSessionConfig::GRAPHICAL_CANVAS;
  $effects = RendererSessionConfig::CANVAS_GLYPH_EFFECTS;
  foreach ([[$effects], [$canvas, $effects]] as $capabilities) {
    expect(fn() => new RendererSessionConfig('Glyph', sys_get_temp_dir(), protocol: RendererProtocolVersion::V1,
      requiredCapabilities: $capabilities))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new RendererSessionConfig('Glyph', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: [$effects]))->toThrow(InvalidArgumentException::class, 'require graphical_canvas');
  $session = new RendererSessionConfig('Glyph', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: [$canvas, $effects]);
  expect($session->hello()->protocol)->toBe(RendererProtocolVersion::V2)
    ->and($session->hello()->payload['requiredCapabilities'])->toBe([$canvas, $effects]);
});

it('rejects unnegotiated glyph effects before enqueue without consuming frame state', function (bool $advertised) {
  $requested = [RendererSessionConfig::GRAPHICAL_CANVAS];
  $acknowledged = [...$requested, ...($advertised ? [RendererSessionConfig::CANVAS_GLYPH_EFFECTS] : [])];
  [$presenter, $transport, $client] = glyphEffectsTestPresenter($requested, $acknowledged);
  $plain = glyphEffectsTestCanvas(null);
  $effects = glyphEffectsTestCanvas(new CanvasGlyphEffects(...glyphEffectsTestArguments()));
  $polls = $transport->polls;
  expect($client->supports(RendererSessionConfig::CANVAS_GLYPH_EFFECTS))->toBeFalse();
  expect(fn() => $presenter->presentCanvas($effects))->toThrow(RendererProtocolException::class, 'canvas_glyph_effects');
  expect($transport->sent)->toBe([])->and($presenter->presentCanvas($plain))->toBeTrue();
  $sent = $transport->sent;
  expect(fn() => $presenter->presentCanvas($effects))->toThrow(RendererProtocolException::class, 'canvas_glyph_effects');
  expect($transport->sent)->toBe($sent)
    ->and($presenter->presentCanvas($plain))->toBeFalse()
    ->and($presenter->presentCanvas(glyphEffectsTestCanvas(null, 'B')))->toBeTrue()
    ->and(array_map(fn($message) => $message->payload['frame'], $transport->sent))->toBe([1, 2])
    ->and($transport->polls)->toBe($polls)->and($transport->shutdowns)->toBe(0);
})->with(['not advertised' => false, 'advertised but not requested' => true]);

it('rejects missing acknowledgement and only queues glyph frames after successful negotiation', function (bool $acknowledged) {
  $capabilities = [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GLYPH_EFFECTS];
  [$presenter, $transport, $client] = glyphEffectsTestPresenter($capabilities, null);
  $canvas = glyphEffectsTestCanvas(new CanvasGlyphEffects(...glyphEffectsTestArguments()));
  expect(fn() => $presenter->presentCanvas($canvas))->toThrow(RendererProtocolException::class);
  expect($transport->sent)->toBe([]);
  $transport->batches[] = [RendererEvent::fromJson(json_encode([
    'protocol' => 2, 'type' => 'ready',
    'capabilities' => $acknowledged ? $capabilities : [RendererSessionConfig::GRAPHICAL_CANVAS],
  ], JSON_THROW_ON_ERROR))];
  if (!$acknowledged) {
    expect(fn() => $client->pump())->toThrow(RendererProtocolException::class, 'canvas_glyph_effects');
    expect(fn() => $presenter->presentCanvas($canvas))->toThrow(RendererProtocolException::class);
    expect($transport->sent)->toBe([]);
    return;
  }
  $client->pump();
  expect($presenter->presentCanvas($canvas))->toBeTrue()
    ->and($presenter->presentCanvas($canvas))->toBeFalse()
    ->and($transport->sent[0]->payload['frame'])->toBe(1)
    ->and($transport->sent[0]->protocol)->toBe(RendererProtocolVersion::V2)
    ->and($transport->sent[0]->payload['canvas'])->toBe($canvas->toArray());
})->with(['missing acknowledgement' => false, 'negotiated effects' => true]);

it('negotiates glyph effects independently from clipping before enqueue', function (string $missing) {
  $capabilities = array_values(array_diff([
    RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GLYPH_EFFECTS,
    RendererSessionConfig::CANVAS_CLIP_OPACITY,
  ], [$missing]));
  [$presenter, $transport] = glyphEffectsTestPresenter($capabilities, $capabilities);
  $canvas = glyphEffectsTestCanvas(new CanvasGlyphEffects(...glyphEffectsTestArguments()), clip: true);
  expect(fn() => $presenter->presentCanvas($canvas))->toThrow(RendererProtocolException::class, $missing);
  expect($transport->sent)->toBe([]);
})->with([RendererSessionConfig::CANVAS_GLYPH_EFFECTS, RendererSessionConfig::CANVAS_CLIP_OPACITY]);
