<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\{BattleCanvasLayout, BattlerContact, BattlerGroundContact, BattlerShadowStyle};
use Ichiloto\Engine\Field\{MapLayer, MapLayerSet};
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasComposite, CanvasCompositeBudget, CanvasCompositeBudgetExceeded,
  CanvasCompositeOperation, CanvasFrameBudget, CanvasImagePreflight, CanvasRectangle, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\{PresentationColor, PresentationSession, PresentationSpritePivot,
  PresentationViewport, PresentationWorld, PresentationWorldCanvas, RendererPresentation};
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\{RendererEvent, RendererGridConfig, RendererSessionConfig};
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function createCompositeBudgetCanvas(int $count, string $prefix = 'surface', int $size = 1, int $fills = 1): PresentationCanvas
{
  $fill = new CanvasCompositeOperation(['type' => 'fill',
    'destination' => ['x' => 0, 'y' => 0, 'width' => $size, 'height' => $size],
    'brush' => ['type' => 'solid', 'color' => ['kind' => 'ansi16', 'index' => 1]]]);
  return new PresentationCanvas(1350, 720, composites: array_map(fn($i) => new CanvasComposite(
    $prefix . ':' . $i, $size, $size, new CanvasRectangle($i, 0, 1, 1), array_fill(0, $fills, $fill)), range(1, $count)));
}

function createCompositeBudgetPresenter(bool $extended): array
{
  $grid = new RendererGridConfig(135, 72, 10, 10);
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $session = new RendererSessionConfig('Composite admission', __DIR__, $grid, RendererProtocolVersion::V2);
  $client->start($session);
  $caps = $session->getNegotiableCapabilities();
  if (!$extended) { $caps = array_values(array_diff($caps, [RendererSessionConfig::CANVAS_COMPOSITE_BUDGET])); }
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
    'capabilities' => $caps], JSON_THROW_ON_ERROR))];
  $client->pump();
  $world = PresentationWorld::getFromLayers(new MapLayerSet([new MapLayer('floor', 0, false, 'synthetic', '....')]), 'field');
  return [new RendererPresentation($client, $grid), $transport, new ConsolePresentationSnapshot(135, 72, []), $world,
    new PresentationViewport(1, 0, 0, new CanvasRectangle(0, 0, 1350, 720), worldId: 'field')];
}

function runWithCompositeBudget(Closure $operation): mixed
{
  return PresentationSession::runForSession(fn(string $cap): bool => $cap === RendererSessionConfig::CANVAS_COMPOSITE_BUDGET, $operation);
}

it('negotiates the optional surface budget only with the protocol-two composite dependencies', function () {
  $required = [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_COMPOSITING,
    RendererSessionConfig::CANVAS_COMPOSITE_BUDGET];
  $session = new RendererSessionConfig('Budget', __DIR__, protocol: RendererProtocolVersion::V2, requiredCapabilities: $required);
  expect($session->hello()->payload['requiredCapabilities'])->toBe($required)
    ->and($session->getNegotiableCapabilities())->toContain(RendererSessionConfig::CANVAS_COMPOSITE_BUDGET);
  foreach (array_slice($required, 0, 2) as $missing) {
    expect(fn() => new RendererSessionConfig('Missing', __DIR__, protocol: RendererProtocolVersion::V2,
      requiredCapabilities: array_values(array_diff($required, [$missing]))))->toThrow(InvalidArgumentException::class, 'Extended composite');
  }
  expect(fn() => new RendererSessionConfig('Legacy', __DIR__, requiredCapabilities: $required))->toThrow(InvalidArgumentException::class);
});

it('restores nested independent hosts and the strict unscoped budget after exceptions', function () {
  expect(CanvasCompositeBudget::getSurfaceLimit())->toBe(8);
  runWithCompositeBudget(function () {
    expect(CanvasCompositeBudget::getSurfaceLimit())->toBe(256)
      ->and(PresentationSession::supportsCapability(RendererSessionConfig::CANVAS_REGION_BUDGET))->toBeFalse();
    expect(fn() => CanvasImagePreflight::runForSession(fn(string $cap): bool => $cap === RendererSessionConfig::CANVAS_REGION_BUDGET,
      function () {
        expect(CanvasCompositeBudget::getSurfaceLimit())->toBe(8)
          ->and(PresentationSession::supportsCapability(RendererSessionConfig::CANVAS_REGION_BUDGET))->toBeTrue();
        throw new RuntimeException('isolated host');
      }))->toThrow(RuntimeException::class, 'isolated host');
    expect(CanvasCompositeBudget::getSurfaceLimit())->toBe(256);
  });
  expect(CanvasCompositeBudget::getSurfaceLimit())->toBe(8);
});

it('admits separate battle shadows and three alpha-masked pulses for both party sizes', function (int $party) {
  $layout = new BattleCanvasLayout(1350, 720, shadow: new BattlerShadowStyle(PresentationColor::ansi16(0)));
  $surfaces = [];
  for ($i = 0; $i < $party + 3; $i++) {
    $contact = BattlerGroundContact::compose('shadow-' . $i, 100 + $i * 60, 400, 190, false, 80,
      0, BattlerContact::SHADOW, $layout, null, null, __DIR__, opacity: .5);
    array_push($surfaces, ...$contact['composites']);
  }
  for ($i = 0; $i < 3; $i++) {
    $rect = ['x' => 0, 'y' => 0, 'width' => 80, 'height' => 190];
    $surfaces[] = new CanvasComposite('pulse-' . $i, 80, 190, new CanvasRectangle(100 + $i * 100, 210, 80, 190),
      [new CanvasCompositeOperation(['type' => 'fill', 'destination' => $rect,
        'brush' => ['type' => 'solid', 'color' => ['kind' => 'ansi16', 'index' => 15]],
        'masks' => [['type' => 'image_alpha', 'asset' => 'synthetic-battler.png', 'destination' => $rect]]])], 101 + $i, .6);
  }
  expect($surfaces)->toHaveCount($party + 6);
  $canvas = runWithCompositeBudget(fn() => new PresentationCanvas(1350, 720, composites: $surfaces));
  expect($canvas->composites)->toBe($surfaces)
    ->and($canvas->composites[0]->layer)->toBe(BattlerGroundContact::SHADOW_LAYER)
    ->and($canvas->composites[0]->opacity)->toBeLessThan(1)
    ->and(array_slice(array_column($canvas->composites, 'layer'), -3))->toBe([101, 102, 103]);
  runWithCompositeBudget(fn() => CanvasFrameBudget::assertCanvases([$canvas]));
  expect(fn() => CanvasFrameBudget::assertCanvases([$canvas]))->toThrow(CanvasCompositeBudgetExceeded::class, 'require negotiated');
})->with([3, 8]);

it('uses the actual client on every submission path even under an opposite outer host', function (string $route, bool $extended) {
  [$presenter, $transport, $text, $world, $viewport] = createCompositeBudgetPresenter($extended);
  $canvas = runWithCompositeBudget(fn() => createCompositeBudgetCanvas(9));
  $before = $presenter->prepareFrame($text);
  $transport->sent = [];
  $group = new PresentationWorldCanvas('group', 0, 0, new PresentationSpritePivot(.5, 1), $canvas);
  $submit = match ($route) {
    'canvas' => fn() => $presenter->presentCanvas($canvas),
    'prepared-canvas' => fn() => $presenter->presentFrame($presenter->prepareCanvas($canvas)),
    'overlay' => fn() => $presenter->present($text, canvasOverlay: $canvas),
    'prepared-overlay' => fn() => $presenter->presentFrame($presenter->prepareFrame($text, canvasOverlay: $canvas)),
    'captured-overlay' => fn() => $presenter->presentFrame($before, $canvas),
    default => fn() => $presenter->present($text, viewport: $viewport->selectWorldCanvases(['group']),
      world: $world, worldCanvases: [$group]),
  };
  PresentationSession::runForSession(fn(string $cap): bool => !$extended, function () use ($extended, $submit, $presenter, $transport) {
    if ($extended) { expect($submit())->toBeTrue()->and($transport->sent)->not->toBe([]); }
    else {
      $accepted = $presenter->captureFrame();
      expect($submit)->toThrow(CanvasCompositeBudgetExceeded::class, '9 > 8')
        ->and($transport->sent)->toBe([])->and($presenter->captureFrame())->toEqual($accepted);
    }
    expect(CanvasCompositeBudget::getSurfaceLimit())->toBe($extended ? 8 : 256);
  });
  expect(CanvasCompositeBudget::getSurfaceLimit())->toBe(8);
})->with(['canvas', 'prepared-canvas', 'overlay', 'prepared-overlay', 'captured-overlay', 'world'])->with([true, false]);

it('counts screen and world roots together without changing the previously accepted retained frame', function () {
  foreach ([false, true] as $extended) {
    [$presenter, $transport, $text, $world, $viewport] = createCompositeBudgetPresenter($extended);
    $count = $extended ? 128 : 4;
    $local = runWithCompositeBudget(fn() => createCompositeBudgetCanvas($count));
    $group = new PresentationWorldCanvas('group', 0, 0, new PresentationSpritePivot(.5, 1), $local);
    $frame = $presenter->prepareFrame($text, viewport: $viewport->selectWorldCanvases(['group']), world: $world,
      canvasOverlay: $local, worldCanvases: [$group]);
    $presenter->presentFrame($frame);
    $accepted = $presenter->captureFrame();
    $transport->sent = [];
    $extra = createCompositeBudgetCanvas(1, 'extra');
    expect(fn() => $presenter->presentFrame($frame, $extra))->toThrow(CanvasCompositeBudgetExceeded::class,
      $extended ? '257 > 256' : '9 > 8')->and($presenter->captureFrame())->toEqual($accepted)->and($transport->sent)->toBe([]);
  }
});

it('retains finite pixel operation node and cold-work limits with the larger surface budget', function () {
  runWithCompositeBudget(function () {
    $small = createCompositeBudgetCanvas(256);
    CanvasFrameBudget::assertCanvases([$small]);
    expect(fn() => CanvasFrameBudget::assertCanvases([$small, createCompositeBudgetCanvas(1)]))
      ->toThrow(CanvasCompositeBudgetExceeded::class, '257 > 256');
    $large = createCompositeBudgetCanvas(2, size: 2048, fills: 0);
    expect(fn() => CanvasFrameBudget::assertCanvases([$large, createCompositeBudgetCanvas(1)]))
      ->toThrow(CanvasCompositeBudgetExceeded::class, '8388609 > 8388608');
    expect(fn() => createCompositeBudgetCanvas(72, fills: 5))->toThrow(CanvasCompositeBudgetExceeded::class, 'operations 360 > 256');
    $data = ['type' => 'image', 'asset' => 'synthetic.png', 'destination' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 16],
      'displacement' => ['columns' => 64, 'rows' => 64, 'offsets' => array_fill(0, 4096, [0, 0])]];
    $node = new PresentationCanvas(1350, 720, composites: [new CanvasComposite('nodes', 16, 16, new CanvasRectangle(0, 0, 16, 16),
      [new CanvasCompositeOperation($data)])]);
    expect(fn() => CanvasFrameBudget::assertCanvases(array_fill(0, 5, $node)))
      ->toThrow(CanvasCompositeBudgetExceeded::class, 'displacement nodes 20480 > 16384');
    $work = createCompositeBudgetCanvas(1, size: 2048, fills: 8);
    expect(fn() => CanvasFrameBudget::assertCanvases([$work, $work]))
      ->toThrow(CanvasCompositeBudgetExceeded::class, '285212672 > 268435456');
  });
});


it('keeps one world overview across all roots under the larger negotiated surface budget', function () {
  runWithCompositeBudget(function () {
    $canvas = new PresentationCanvas(32, 32, composites: [new CanvasComposite('overview', 16, 16,
      new CanvasRectangle(0, 0, 16, 16), [new CanvasCompositeOperation(['type' => 'world', 'worldId' => 'map',
        'destination' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 16], 'subjects' => []])])]);
    expect(fn() => CanvasFrameBudget::assertCanvases([$canvas, $canvas]))
      ->toThrow(CanvasCompositeBudgetExceeded::class, 'world operations 2 > 1');
  });
});
