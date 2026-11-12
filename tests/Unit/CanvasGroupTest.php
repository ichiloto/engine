<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasGroup, CanvasFrameBudget, CanvasImage,
  CanvasImagePreflight, CanvasRectangle, CanvasViewportFit, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\{RendererEvent, RendererGridConfig, RendererSessionConfig};
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

function createGroupPresenter(array $capabilities): array
{
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Group', getTestTemporaryDirectory(),
    protocol: RendererProtocolVersion::V2, requiredCapabilities: $capabilities));
  $transport->batches[] = [RendererEvent::fromJson(json_encode([
    'protocol' => 2, 'type' => 'ready', 'capabilities' => $capabilities,
  ], JSON_THROW_ON_ERROR))];
  $client->pump();
  return [new RendererPresentation($client, new RendererGridConfig(2, 1)), $transport];
}

function createGroupDrawing(int $count = 1): PresentationCanvas
{
  return new PresentationCanvas(20, 20, array_map(static fn(int $i) =>
    new CanvasImage('part-' . $i, 'test-sprite.png', new CanvasRectangle(0, 0, 20, 20)), range(1, $count)));
}

it('keeps negative fractional group placement and child source geometry intact', function () {
  $child = createGroupDrawing();
  $group = new CanvasGroup('field', -10.25, 4.5, $child, -2);
  $root = new PresentationCanvas(20, 20, groups: [$group]);
  expect($root->toArray()['groups'][0])->toBe(['id' => 'field', 'origin' => ['x' => -10.25, 'y' => 4.5],
    'layer' => -2, 'canvas' => $child->toArray()])
    ->and($group->canvas)->toBe($child)->and($child->images[0]->destination->x)->toBe(0.0)
    ->and((new PresentationCanvas(20, 20))->toArray())->not->toHaveKey('groups');
});

it('intersects group protection through ancestor clips without moving child paint', function () {
  $inner = new PresentationCanvas(20, 20, groups: [new CanvasGroup('inner', -5, 4, createGroupDrawing())]);
  $root = new PresentationCanvas(20, 20, groups: [new CanvasGroup('outer', 2, -2, $inner,
    clipRect: new CanvasRectangle(4, 1, 8, 9))]);
  expect(array_map(fn($area) => $area->toArray(), $root->getOverlayProtection()))
    ->toBe([['x' => 4.0, 'y' => 2.0, 'width' => 8.0, 'height' => 8.0]])
    ->and((new PresentationCanvas(20, 20, groups: [new CanvasGroup('outside', -25, 0, createGroupDrawing())]))->getOverlayProtection())->toBe([]);
});

it('rejects nonfinite or oversized transforms and child viewport changes', function (float $x, float $y) {
  expect(fn() => new CanvasGroup('bad', $x, $y, createGroupDrawing()))->toThrow(InvalidArgumentException::class);
})->with([[NAN, 0.0], [INF, 0.0], [0.0, -INF], [16384.0, 0.0], [-16385.0, 0.0]]);

it('requires unique local group identities and parent-bounded clips', function () {
  $child = createGroupDrawing(); $group = new CanvasGroup('same', 0, 0, $child);
  foreach ([[$group, $group], ['named' => $group], [new stdClass()]] as $groups) {
    expect(fn() => new PresentationCanvas(20, 20, groups: $groups))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new PresentationCanvas(20, 20, groups: [new CanvasGroup('clip', 0, 0, $child,
    clipRect: new CanvasRectangle(0, 0, 21, 20))]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new CanvasGroup('fit', 0, 0, new PresentationCanvas(20, 20, viewportFit: CanvasViewportFit::CONTAIN)))
      ->toThrow(InvalidArgumentException::class);
});

it('retains local namespaces and stable layer ordering across screen overlays', function () {
  $child = createGroupDrawing();
  $base = new PresentationCanvas(20, 20, groups: [new CanvasGroup('field', 0, 0, $child, 17)]);
  $overlay = new PresentationCanvas(20, 20, $child->images, groups: [new CanvasGroup('field', -3, 0, $child)]);
  $result = PresentationCanvas::composeOverlay($base, $overlay, 'modal-');
  expect(array_column($result->groups, 'id'))->toBe(['field', 'modal-field'])
    ->and($result->groups[1]->canvas)->toBe($child)->and($result->groups[1]->layer)->toBe(18)
    ->and($result->images[0]->layer)->toBe(18)->and($result->groups[1]->x)->toBe(-3.0);
});

it('caps drawn group instances across roots and accepts eight nested levels', function () {
  $leaf = new PresentationCanvas(1, 1);
  $groups = array_map(fn($i) => new CanvasGroup('g' . $i, 0, 0, $leaf), range(1, 64));
  $root = new PresentationCanvas(1, 1, groups: $groups);
  CanvasFrameBudget::assertCanvases([$root, $root]);
  expect(fn() => CanvasFrameBudget::assertCanvases([$root, $root,
    new PresentationCanvas(1, 1, groups: [$groups[0]])]))->toThrow(InvalidArgumentException::class, '128');
  $nested = $leaf;
  for ($i = 0; $i < 8; $i++) { $nested = new PresentationCanvas(1, 1, groups: [new CanvasGroup('level', 0, 0, $nested)]); }
  expect(CanvasFrameBudget::getCanvases([$nested]))->toHaveCount(9)
    ->and(fn() => new PresentationCanvas(1, 1, groups: [new CanvasGroup('too-deep', 0, 0, $nested)]))
      ->toThrow(InvalidArgumentException::class, 'eight');
});

it('charges repeated local drawings against the unchanged shared image pool', function () {
  $child = createGroupDrawing(512);
  $group = new CanvasGroup('a', 0, 0, $child);
  $second = new CanvasGroup('b', 0, 0, $child);
  $root = new PresentationCanvas(20, 20, groups: [$group, $second]);
  CanvasFrameBudget::assertCanvases([$root]);
  expect(fn() => new PresentationCanvas(20, 20, groups: [$group, $second, new CanvasGroup('c', 0, 0, $child)]))
    ->toThrow(InvalidArgumentException::class, 'Aggregate');
});

it('preflights hidden and reused descendants in the same asset-root scope', function () {
  $drawing = createGroupDrawing(); $root = __DIR__ . '/../Fixtures/Renderer';
  $grouped = new PresentationCanvas(20, 20, groups: [new CanvasGroup('hidden', -40, 0, $drawing),
    new CanvasGroup('reused', 0, 0, $drawing)]);
  expect(CanvasImagePreflight::inspectCanvases([$grouped], $root))
    ->toBe(CanvasImagePreflight::inspect($drawing->images, $root));
  $missing = new PresentationCanvas(1, 1, [new CanvasImage('missing', 'absent.png', new CanvasRectangle(0, 0, 1, 1))]);
  expect(fn() => CanvasImagePreflight::inspectCanvases([new PresentationCanvas(1, 1,
    groups: [new CanvasGroup('hidden-missing', -10, 0, $missing)])], $root))->toThrow(RuntimeException::class);
});

it('rejects unnegotiated groups atomically and inspects nested feature requirements', function () {
  [$presenter, $transport] = createGroupPresenter([RendererSessionConfig::GRAPHICAL_CANVAS]);
  $grouped = new PresentationCanvas(20, 20, groups: [new CanvasGroup('field', 0, 0, createGroupDrawing())]);
  $before = count($transport->sent);
  expect(fn() => $presenter->prepareCanvas($grouped))->toThrow(RendererProtocolException::class, 'canvas_groups')
    ->and(count($transport->sent))->toBe($before);
  [$presenter, $transport] = createGroupPresenter([RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GROUPS]);
  $child = new PresentationCanvas(20, 20, [new CanvasImage('mirrored', 'test-sprite.png', new CanvasRectangle(0, 0, 20, 20), flipX: true)]);
  $before = count($transport->sent);
  expect(fn() => $presenter->prepareCanvas(new PresentationCanvas(20, 20, groups: [new CanvasGroup('field', 0, 0, $child)])))
    ->toThrow(RendererProtocolException::class, 'canvas_image_flip')->and(count($transport->sent))->toBe($before);
});

it('updates and retires retained local groups without replaying unchanged HUD drawings', function () {
  [$presenter, $transport] = createGroupPresenter([RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GROUPS]);
  $child = createGroupDrawing(); $hud = new CanvasImage('hud', 'hud.png', new CanvasRectangle(0, 0, 1, 1));
  foreach ([0.0, -10.0, null] as $offset) {
    $canvas = new PresentationCanvas(20, 20, [$hud], groups: $offset === null ? [] : [new CanvasGroup('field', $offset, 0, $child)]);
    expect($presenter->presentCanvas($canvas))->toBeTrue(); $transport->acknowledgeLatestFrame($presenter);
  }
  $frames = RetainedFrameState::replay($transport->sent);
  expect($frames[0]['canvas']['groups'][0]['origin']['x'])->toBe(0.0)
    ->and($frames[1]['canvas']['groups'][0]['origin']['x'])->toBe(-10.0)
    ->and($frames[2]['canvas'])->not->toHaveKey('groups');
  $packets = array_values(array_filter($transport->sent, fn($message) => isset($message->payload['operations'])));
  expect(array_column($packets[1]->payload['operations'], 'kind'))->toBe(['canvas_group'])
    ->and($packets[2]->payload['operations'])->toBe([['op' => 'remove', 'kind' => 'canvas_group', 'id' => 'field']]);
});


it('validates group layers against the same signed wire range as other canvas drawings', function () {
  $child = new PresentationCanvas(1, 1);
  foreach ([-2147483648, 2147483647] as $layer) {
    expect((new CanvasGroup('edge', 0, 0, $child, $layer))->toArray()['layer'])->toBe($layer);
  }
  foreach ([-2147483649, 2147483648] as $layer) {
    expect(fn() => new CanvasGroup('outside', 0, 0, $child, $layer))->toThrow(InvalidArgumentException::class);
  }
});
