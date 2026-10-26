<?php

declare(strict_types=1);

use Ichiloto\Engine\Field\{MapLayer, MapLayerSet};
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasFrameBudget, CanvasLabel, CanvasTextLayer, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\{PresentationSpritePivot, PresentationViewport, PresentationWorld, PresentationWorldCanvas, RendererPresentation};
use Ichiloto\Engine\Rendering\Presentation\Text\{TextBounds, TextMeasurement, TextMetrics};
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\{RendererEvent, RendererGridConfig, RendererSessionConfig};
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function createLabelInstanceCanvas(int $count, string $prefix = 'instance'): PresentationCanvas
{
  $bounds = new TextBounds(0, 0, 8, 8);
  $measurement = new TextMeasurement('shared-value', new TextMetrics(8, 6, $bounds, $bounds, $bounds));
  return new PresentationCanvas(1350, 720, labels: array_map(
    fn($i) => new CanvasLabel($prefix . ':' . $i, $measurement, ($i % 100) * 10, intdiv($i, 100) * 10),
    range(0, $count - 1)));
}

function createLabelInstancePresenter(bool $instances = true): array
{
  $grid = new RendererGridConfig(135, 72, 10, 10);
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $session = new RendererSessionConfig('Instance admission', sys_get_temp_dir(), $grid, RendererProtocolVersion::V2);
  $client->start($session);
  $capabilities = $session->getNegotiableCapabilities();
  if (!$instances) { $capabilities = array_values(array_diff($capabilities, [RendererSessionConfig::CANVAS_LABEL_INSTANCES])); }
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
    'capabilities' => $capabilities], JSON_THROW_ON_ERROR))];
  $client->pump();
  $world = PresentationWorld::getFromLayers(new MapLayerSet([new MapLayer('floor', 0, false, 'synthetic', '....')]), 'field');
  return [new RendererPresentation($client, $grid), $transport, new ConsolePresentationSnapshot(135, 72, []), $world,
    new PresentationViewport(1, 0, 0, new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle(0, 0, 1350, 720), worldId: 'field')];
}

function createLabelInstanceGroup(string $id, PresentationCanvas $canvas): PresentationWorldCanvas
{
  return new PresentationWorldCanvas($id, 0, 0, new PresentationSpritePivot(.5, 1), $canvas);
}

it('requires explicit instance support in protocol two with the project-label dependencies', function () {
  $required = [RendererSessionConfig::CANVAS_LABEL_INSTANCES, RendererSessionConfig::GRAPHICAL_CANVAS,
    RendererSessionConfig::CANVAS_LABELS, RendererSessionConfig::TEXT_LAYOUT];
  $session = new RendererSessionConfig('Instance negotiation', __DIR__, protocol: RendererProtocolVersion::V2,
    requiredCapabilities: $required);
  expect($session->hello()->payload['requiredCapabilities'])->toBe($required)
    ->and($session->getNegotiableCapabilities())->toContain(RendererSessionConfig::CANVAS_LABEL_INSTANCES);
  foreach (array_slice($required, 1) as $missing) {
    expect(fn() => new RendererSessionConfig('Missing', __DIR__, protocol: RendererProtocolVersion::V2,
      requiredCapabilities: array_values(array_diff($required, [$missing]))))
      ->toThrow(InvalidArgumentException::class, 'Canvas label instances');
  }
  expect(fn() => new RendererSessionConfig('Legacy', __DIR__, requiredCapabilities: [RendererSessionConfig::CANVAS_LABEL_INSTANCES]))
    ->toThrow(InvalidArgumentException::class);
});

it('keeps the legacy combined 64-placement admission on every submission path', function (string $route) {
  [$presenter, $transport, $text, $world, $viewport] = createLabelInstancePresenter(false);
  $base = createLabelInstanceCanvas(32);
  $presenter->presentCanvas($base);
  $before = $presenter->captureFrame();
  $transport->sent = [];
  $canvas = createLabelInstanceCanvas(65);
  $group = createLabelInstanceGroup('group', $canvas);
  $submit = match ($route) {
    'canvas' => fn() => $presenter->presentCanvas($canvas),
    'prepared-canvas' => fn() => $presenter->prepareCanvas($canvas),
    'overlay' => fn() => $presenter->present($text, canvasOverlay: $canvas),
    'prepared-overlay' => fn() => $presenter->prepareFrame($text, canvasOverlay: $canvas),
    'captured-overlay' => fn() => $presenter->presentFrame($before, createLabelInstanceCanvas(33, 'overlay')),
    default => fn() => $presenter->present($text, viewport: $viewport->selectWorldCanvases(['group']),
      world: $world, worldCanvases: [$group]),
  };
  expect($submit)->toThrow(RendererProtocolException::class, 'canvas_label_instances')
    ->and($transport->sent)->toBe([])->and($presenter->captureFrame())->toEqual($before);
})->with(['canvas', 'prepared-canvas', 'overlay', 'prepared-overlay', 'captured-overlay', 'world']);

it('applies legacy admission across roots, not separately to each canvas', function () {
  [$presenter, $transport, $text, $world, $viewport] = createLabelInstancePresenter(false);
  $group = createLabelInstanceGroup('group', createLabelInstanceCanvas(32));
  $viewport = $viewport->selectWorldCanvases(['group']);
  $presenter->present($text, viewport: $viewport, world: $world, worldCanvases: [$group]);
  $before = $presenter->captureFrame();
  $transport->sent = [];
  expect(fn() => $presenter->presentFrame($before, createLabelInstanceCanvas(33, 'screen')))
    ->toThrow(RendererProtocolException::class, 'canvas_label_instances')
    ->and($presenter->captureFrame())->toEqual($before)->and($transport->sent)->toBe([]);
  expect(fn() => $presenter->prepareFrame($text, viewport: $viewport, world: $world,
    canvasOverlay: createLabelInstanceCanvas(33, 'screen'), worldCanvases: [$group]))
    ->toThrow(RendererProtocolException::class, 'canvas_label_instances');
  $prepared = $presenter->prepareFrame($text, viewport: $viewport, world: $world,
    canvasOverlay: createLabelInstanceCanvas(32, 'screen'), worldCanvases: [$group]);
  expect($prepared->canvas->labels)->toHaveCount(32)->and($prepared->worldCanvases[0]->canvas->labels)->toHaveCount(32);
});

it('preserves the separate hard label and grid bounds across all roots', function () {
  $a = createLabelInstanceCanvas(512);
  $b = createLabelInstanceCanvas(512);
  CanvasFrameBudget::assertCanvases([$a, $b]);
  expect(fn() => CanvasFrameBudget::assertCanvases([$a, $b, createLabelInstanceCanvas(1)]))
    ->toThrow(InvalidArgumentException::class, 'label-instance');
  $grids = array_map(fn($i) => new CanvasTextLayer('grid:' . $i, 0, 0, 0, new RendererGridConfig(1, 1, 1, 1), []), range(1, 64));
  CanvasFrameBudget::assertCanvases([new PresentationCanvas(1350, 720, textLayers: $grids, labels: $a->labels), $b]);
  expect(fn() => CanvasFrameBudget::assertCanvases([new PresentationCanvas(1350, 720, textLayers: $grids),
    new PresentationCanvas(1350, 720, textLayers: [$grids[0]])]))
    ->toThrow(InvalidArgumentException::class, 'grid-text');
});

it('admits a complete repeated-label surface without preparing a layout for each occurrence', function () {
  [$presenter, $transport] = createLabelInstancePresenter();
  // Capacity only: 43 visible cards with four corner fields, center and ten pips, plus a 20-entry ledger.
  // This synthetic fixture does not ratify any game's hand, Joker, score or layout rules.
  $canvas = createLabelInstanceCanvas(43 * 15 + 20);
  $frame = $presenter->prepareCanvas($canvas);
  expect($frame->values['canvas_label'])->toHaveCount(665)
    ->and(array_unique(array_column($frame->values['canvas_label'], 'layoutId')))->toBe(['shared-value'])
    ->and($transport->sent)->toBe([]);
  $presenter->presentFrame($frame);
  for ($tick = 0; $tick < 20 && $presenter->hasPendingUpload(); $tick++) {
    $last = end($transport->sent)->payload;
    $presenter->acknowledge($last['generation'], $last['present']);
    $presenter->presentFrame($frame);
  }
  expect($presenter->hasPendingUpload())->toBeFalse()
    ->and($presenter->captureFrame()->values['canvas_label'])->toHaveCount(665)
    ->and(array_column($transport->sent, 'type'))->not->toContain(\Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType::PREPARE_TEXT);
  $transport->sent = [];
  expect($presenter->presentFrame($frame))->toBeFalse()->and($transport->sent)->toBe([]);
});
