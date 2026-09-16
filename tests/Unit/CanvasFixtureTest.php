<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGlyphEffects;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicator;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function canvasContractFixture(string $file, string $corpus = 'graphical-canvas'): array
{
  return json_decode(file_get_contents(__DIR__ . '/../Fixtures/Renderer/' . $corpus . '/' . $file), true, flags: JSON_THROW_ON_ERROR);
}

/** Test-only construction from known fixture shapes, not an inbound protocol parser. */
function typedCanvasFixture(array $canvas): PresentationCanvas
{
  $rect = fn(array $r) => new CanvasRectangle($r['x'], $r['y'], $r['width'], $r['height']);
  $color = static fn(?array $c) => $c === null ? null : match ($c['kind']) {
    'ansi16' => PresentationColor::ansi16($c['index']),
    'ansi256' => PresentationColor::ansi256($c['index']),
    'rgb' => PresentationColor::rgb($c['r'], $c['g'], $c['b']),
  };
  $images = [];
  foreach ($canvas['images'] ?? [] as $i) {
    $crop = isset($i['sourceRect']) ? new SpriteSourceRect(...$i['sourceRect']) : null;
    $images[] = new CanvasImage($i['id'], $i['asset'], $rect($i['destination']), $i['layer'], $crop,
      array_key_exists('opacity', $i) ? $i['opacity'] : 1,
      array_key_exists('clipRect', $i) ? $rect($i['clipRect']) : null);
  }
  $indicators = [];
  foreach ($canvas['indicators'] ?? [] as $i) {
    $indicators[] = new CanvasIndicator($i['id'], $i['imageId'], CanvasIndicatorKind::from($i['kind']),
      $rect($i['bounds']), $i['strokeWidth'], $color($i['color']), $i['layer']);
  }
  $text = [];
  foreach ($canvas['textLayers'] ?? [] as $t) {
    $runs = array_map(fn($r) => new PresentationTextRun($r['row'], $r['column'], $r['text'],
      $color($r['foreground']), $color($r['background'])), $t['runs']);
    $effects = null;
    if (isset($t['glyphEffects'])) {
      $outline = $t['glyphEffects']['outline'];
      $shadow = $t['glyphEffects']['shadow'];
      $effects = new CanvasGlyphEffects($outline['width'], $color($outline['color']),
        $shadow['offsetX'], $shadow['offsetY'], $shadow['sigma'], $shadow['opacity'], $color($shadow['color']));
    }
    $text[] = new CanvasTextLayer($t['id'], $t['layer'], $t['origin']['x'], $t['origin']['y'],
      new RendererGridConfig(...$t['grid']), $runs,
      array_key_exists('clipRect', $t) ? $rect($t['clipRect']) : null,
      array_key_exists('opacity', $t) ? $t['opacity'] : 1, $effects);
  }
  return new PresentationCanvas($canvas['width'], $canvas['height'], $images, $indicators, $text);
}

it('preserves the complete canonical native corpus and synthetic asset hashes', function () {
  foreach ([
    'graphical-canvas' => ['1cc3acd92570105e13eced9d4fc9d81dcea004dc666d8331aca5ad2897635790', 139],
    'canvas-clip-opacity' => ['9619484c10e87e33a3607e456f6a6bf17b9be5db71e56d885cebf8fccc694d11', 75],
    'canvas-glyph-effects' => ['4d37e341ced397fba4fb37d180ab3ae6804d59722d612c9cf1e4294fa266e524', 90],
  ] as $corpus => [$manifestHash, $count]) {
    $root = __DIR__ . '/../Fixtures/Renderer/' . $corpus . '/';
    expect(hash_file('sha256', $root . 'manifest.json'))->toBe($manifestHash);
    foreach (file($root . 'SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      [$hash, $file] = explode('  ', $line, 2);
      expect(hash_file('sha256', $root . $file), $corpus . '/' . $file)->toBe($hash);
    }
    expect(canvasContractFixture('manifest.json', $corpus)['cases'])->toHaveCount($count);
  }
});

it('matches native accepted payloads through PHP values with only specified omission defaults', function ($file, $corpus = 'graphical-canvas') {
  $fixture = canvasContractFixture($file, $corpus);
  $canvas = isset($fixture['canvas']) ? typedCanvasFixture($fixture['canvas']) : null;
  $emitted = json_decode(new StyledPresentationFrame($fixture['frame'], canvas: $canvas)->toRendererMessage()->encode(),
    true, flags: JSON_THROW_ON_ERROR);
  // The Engine emits all lists and omits default opacity; both native forms are in the corpus.
  if (isset($fixture['canvas'])) {
    foreach (['images', 'indicators', 'textLayers'] as $list) { $fixture['canvas'][$list] ??= []; }
    foreach ($fixture['canvas']['images'] as &$image) {
      if (($image['opacity'] ?? null) === 1 || ($image['opacity'] ?? null) === 1.0) { unset($image['opacity']); }
    }
    unset($image);
    foreach ($fixture['canvas']['textLayers'] as &$text) {
      if (($text['opacity'] ?? null) === 1 || ($text['opacity'] ?? null) === 1.0) { unset($text['opacity']); }
    }
    unset($text);
  }
  expect($emitted)->toEqual($fixture);
})->with([
  ...array_column(array_filter(canvasContractFixture('manifest.json')['cases'], fn($c) => $c['stage'] === 'accept'), 'file'),
  ...array_map(fn($c) => [$c['file'], 'canvas-clip-opacity'], array_values(array_filter(
    canvasContractFixture('manifest.json', 'canvas-clip-opacity')['cases'], fn($c) => $c['stage'] === 'accept' && $c['file'] !== 'hello.json'))),
  ...array_map(fn($c) => [$c['file'], 'canvas-glyph-effects'], array_values(array_filter(
    canvasContractFixture('manifest.json', 'canvas-glyph-effects')['cases'], fn($c) => $c['stage'] === 'accept' && $c['file'] !== 'hello.json'))),
]);

it('rejects canonical model-invalid values without duplicating native JSON and filesystem validation', function ($file, $corpus = 'graphical-canvas') {
  try {
    typedCanvasFixture(canvasContractFixture($file, $corpus)['canvas']);
  } catch (InvalidArgumentException|TypeError|ValueError) {
    expect(true)->toBeTrue();
    return;
  }
  test()->fail("Model accepted invalid fixture {$file}.");
})->with([
  ...array_values(array_filter(array_column(canvasContractFixture('manifest.json')['cases'], 'file'),
    static fn(string $f) => preg_match('/^invalid-(width-|height-|destination-|opacity-|stroke-|(?:images|indicators|textLayers)-(?:duplicate|id-|layer-)|(?:image|indicator|text-layer|text-run|text-scalar)-limit|ui-(?:grid-|run-outside|control)|indicator-(?:reference|kind)|asset-(?:empty|absolute|long)|crop-(?:zero|float|overflow)|color-component)/', $f) === 1)),
  ...array_map(fn($c) => [$c['file'], 'canvas-glyph-effects'], array_values(array_filter(
    canvasContractFixture('manifest.json', 'canvas-glyph-effects')['cases'],
    static fn($c) => preg_match('/^invalid-(?:(?:width|offsetX|offsetY|sigma|opacity)-(?:below|above|null|bool|string)\.json|(?:outline|shadow)-color-index\.json|expanded-bounds-)/', $c['file']) === 1))),
]);

it('rejects each canonical negotiation failure before enqueueing', function ($case, $corpus = 'graphical-canvas') {
  $hello = canvasContractFixture('hello.json', $corpus);
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig($hello['title'], dirname(__DIR__) . '/Fixtures/Renderer',
    new RendererGridConfig(...$hello['grid']), RendererProtocolVersion::V2, $case['capabilities']));
  $transport->batches[] = [RendererEvent::fromJson(json_encode([
    'protocol' => 2, 'type' => 'ready', 'capabilities' => $case['capabilities'],
  ], JSON_THROW_ON_ERROR))];
  $client->pump();
  $presenter = new RendererPresentation($client, new RendererGridConfig(...$hello['grid']));
  expect(fn() => $presenter->presentCanvas(typedCanvasFixture(canvasContractFixture($case['file'], $corpus)['canvas'])))
    ->toThrow(RendererProtocolException::class)->and($transport->sent)->toBe([]);
})->with([
  ...array_map(fn($c) => [$c], array_values(array_filter(canvasContractFixture('manifest.json')['cases'], fn($c) => $c['stage'] === 'session'))),
  ...array_map(fn($c) => [$c, 'canvas-glyph-effects'], array_values(array_filter(
    canvasContractFixture('manifest.json', 'canvas-glyph-effects')['cases'], fn($c) => $c['stage'] === 'session'))),
]);
