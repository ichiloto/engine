<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasGroup, CanvasGroupScale, CanvasColorTransform, CanvasFrameBudget, CanvasImage,
  CanvasImagePreflight, CanvasRectangle, CanvasViewportFit, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\{RendererPresentation, PresentationSpritePivot};
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


it('preserves group colour through nested overlays and retained updates without treating siblings', function () {
  $invert = new CanvasColorTransform([[-1, 0, 0], [0, -1, 0], [0, 0, -1]], [1, 1, 1]);
  $dim = new CanvasColorTransform([[0.4, 0, 0], [0, 0.4, 0], [0, 0, 0.4]], [0, 0, 0]);
  $leaf = createGroupDrawing();
  $inner = new PresentationCanvas(20, 20, groups: [new CanvasGroup('body', 0, 0, $leaf, colorTransform: $invert)]);
  $overlay = new PresentationCanvas(20, 20, groups: [new CanvasGroup('field', 2, -1, $inner, colorTransform: $dim)]);
  $stacked = PresentationCanvas::composeOverlay(new PresentationCanvas(20, 20), $overlay, 'modal-');
  expect($stacked->groups[0]->colorTransform)->toBe($dim)
    ->and($stacked->groups[0]->canvas->groups[0]->colorTransform)->toBe($invert);
  [$presenter, $transport] = createGroupPresenter([RendererSessionConfig::GRAPHICAL_CANVAS,
    RendererSessionConfig::CANVAS_GROUPS, RendererSessionConfig::CANVAS_GROUP_COLOR, RendererSessionConfig::CANVAS_REGION_BUDGET]);
  $hud = new CanvasImage('hud', 'hud.png', new CanvasRectangle(0, 0, 1, 1));
  foreach ([$invert, $dim, null] as $treatment) {
    $candidate = new PresentationCanvas(20, 20, [$hud], groups: [new CanvasGroup('field', 0, 0, $leaf, colorTransform: $treatment)]);
    expect($presenter->presentCanvas($candidate))->toBeTrue(); $transport->acknowledgeLatestFrame($presenter);
  }
  $frames = RetainedFrameState::replay($transport->sent);
  expect($frames[0]['canvas']['groups'][0]['colorTransform'])->toBe($invert->toArray())
    ->and($frames[1]['canvas']['groups'][0]['colorTransform'])->toBe($dim->toArray())
    ->and($frames[2]['canvas']['groups'][0])->not->toHaveKey('colorTransform')
    ->and($frames[0]['canvas']['images'][0])->not->toHaveKey('colorTransform');
  $packets = array_values(array_filter($transport->sent, fn($message) => isset($message->payload['operations'])));
  expect(array_column($packets[1]->payload['operations'], 'kind'))->toBe(['canvas_group'])
    ->and(array_column($packets[2]->payload['operations'], 'kind'))->toBe(['canvas_group']);
});

it('refuses missing group colour or preparation budget before sending any partial frame', function () {
  $invert = new CanvasColorTransform([[-1, 0, 0], [0, -1, 0], [0, 0, -1]], [1, 1, 1]);
  $leaf = createGroupDrawing();
  $child = new PresentationCanvas(20, 20, groups: [new CanvasGroup('treated', 0, 0, $leaf, colorTransform: $invert)]);
  $nested = new PresentationCanvas(20, 20, groups: [new CanvasGroup('outer', 0, 0, $child)]);
  [$presenter, $transport] = createGroupPresenter([RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GROUPS]);
  $before = count($transport->sent);
  expect(fn() => $presenter->prepareCanvas($nested))->toThrow(RendererProtocolException::class, 'canvas_group_color')
    ->and(count($transport->sent))->toBe($before);
  $transport = new FakeRendererTransport(); $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Optional colour', getTestTemporaryDirectory(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GROUPS]));
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
    'capabilities' => [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GROUPS,
      RendererSessionConfig::CANVAS_GROUP_COLOR]], JSON_THROW_ON_ERROR))];
  $client->pump(); $presenter = new RendererPresentation($client, new RendererGridConfig(2, 1));
  $before = count($transport->sent);
  expect(fn() => $presenter->prepareCanvas($nested))->toThrow(RendererProtocolException::class, 'canvas_region_budget')
    ->and(count($transport->sent))->toBe($before);
  expect(fn() => new RendererSessionConfig('No V1 colour', getTestTemporaryDirectory(),
    requiredCapabilities: [RendererSessionConfig::CANVAS_GROUP_COLOR]))->toThrow(InvalidArgumentException::class);
  $session = new RendererSessionConfig('Group', getTestTemporaryDirectory(), protocol: RendererProtocolVersion::V2);
  expect($session->getNegotiableCapabilities())->toContain(RendererSessionConfig::CANVAS_GROUP_COLOR);
  foreach ([[], [RendererSessionConfig::GRAPHICAL_CANVAS],
    [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GROUPS],
    [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_REGION_BUDGET]] as $requirements) {
    expect(fn() => new RendererSessionConfig('Group', getTestTemporaryDirectory(), protocol: RendererProtocolVersion::V2,
      requiredCapabilities: [...$requirements, RendererSessionConfig::CANVAS_GROUP_COLOR]))
      ->toThrow(InvalidArgumentException::class, 'Group colour');
  }
});


it('projects fractional scale geometry and protection before the fixed parent clip', function () {
  $area = new CanvasRectangle(2, 3, 4, 5);
  $child = new PresentationCanvas(20, 20, protectedAreas: [$area]);
  $scale = new CanvasGroupScale(1.5, new PresentationSpritePivot(.25, .5));
  $group = new CanvasGroup('scaled', 1.25, -2.5, $child, clipRect: new CanvasRectangle(2, 0, 5, 4), scale: $scale);
  expect($group->projectRectangle($area)->toArray())->toBe(['x' => 1.75, 'y' => -3.0, 'width' => 6.0, 'height' => 7.5])
    ->and($group->getOverlayProtection(20, 20)[0]->toArray())->toBe(['x' => 2.0, 'y' => 0.0, 'width' => 5.0, 'height' => 4.0])
    ->and($group->toArray()['scale'])->toBe(['factor' => 1.5, 'origin' => ['x' => .25, 'y' => .5]])
    ->and($group->toArray()['clipRect'])->toBe(['x' => 2.0, 'y' => 0.0, 'width' => 5.0, 'height' => 4.0])
    ->and($child->protectedAreas[0])->toBe($area);
  $outer = new CanvasGroup('outer', 3, 5, new PresentationCanvas(20, 20, groups: [$group]),
    scale: new CanvasGroupScale(2, new PresentationSpritePivot(.5, .5)));
  expect($outer->getOverlayProtection(20, 20)[0]->toArray())->toBe(['x' => 0.0, 'y' => 0.0, 'width' => 7.0, 'height' => 3.0]);
});

it('keeps identity scale wire-compatible and rejects malformed or unbounded scale', function () {
  $leaf = createGroupDrawing();
  expect((new CanvasGroup('field', 0, 0, $leaf, scale: new CanvasGroupScale(1, new PresentationSpritePivot(0, 1))))->toArray())
    ->toBe((new CanvasGroup('field', 0, 0, $leaf))->toArray());
  foreach ([0.0, -1.0, NAN, INF, 8.00001] as $factor) {
    expect(fn() => new CanvasGroupScale($factor, new PresentationSpritePivot(.5, .5)))->toThrow(InvalidArgumentException::class);
  }
  foreach ([[], ['factor' => 2], ['factor' => true, 'origin' => ['x' => .5, 'y' => .5]],
    ['factor' => '2', 'origin' => ['x' => .5, 'y' => .5]], ['factor' => 0, 'origin' => ['x' => .5, 'y' => .5]],
    ['factor' => 2, 'origin' => ['x' => -.1, 'y' => .5]], ['factor' => 2, 'origin' => null],
    ['factor' => 2, 'origin' => ['x' => .5, 'y' => .5], 'stretchY' => 3]] as $data) {
    expect(fn() => CanvasGroupScale::fromArray($data))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new CanvasGroup('outside-origin', -17000, 0, new PresentationCanvas(1000, 20),
    scale: new CanvasGroupScale(.1, new PresentationSpritePivot(1, 0))))->toThrow(InvalidArgumentException::class);
  $scale = CanvasGroupScale::fromArray(['factor' => 8, 'origin' => ['x' => 0, 'y' => 0]]);
  expect($scale->factor)->toBe(8.0)
    ->and(fn() => new CanvasGroup('too-wide', 16300, 0, $leaf, scale: $scale))->toThrow(InvalidArgumentException::class);
});

it('retains paint-time scale without changing child preparation or replaying the HUD', function () {
  $caps = [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GROUPS,
    RendererSessionConfig::CANVAS_GROUP_SCALE, RendererSessionConfig::CANVAS_REGION_BUDGET];
  [$presenter, $transport] = createGroupPresenter($caps);
  $leaf = createGroupDrawing(); $hud = new CanvasImage('hud', 'hud.png', new CanvasRectangle(0, 0, 1, 1));
  $baseline = CanvasImagePreflight::inspectCanvases([$leaf], __DIR__ . '/../Fixtures/Renderer');
  foreach ([1.25, 1.5, 1.0] as $factor) {
    $group = new CanvasGroup('field', 0, 0, $leaf, scale: new CanvasGroupScale($factor, new PresentationSpritePivot(.5, .5)));
    $canvas = new PresentationCanvas(20, 20, [$hud], groups: [$group]);
    expect($group->canvas)->toBe($leaf)
      ->and(CanvasImagePreflight::inspectCanvases([new PresentationCanvas(20, 20, groups: [$group])], __DIR__ . '/../Fixtures/Renderer'))->toBe($baseline)
      ->and($presenter->presentCanvas($canvas))->toBeTrue();
    $transport->acknowledgeLatestFrame($presenter);
  }
  $frames = RetainedFrameState::replay($transport->sent);
  expect($frames[0]['canvas']['groups'][0]['scale']['factor'])->toBe(1.25)
    ->and($frames[1]['canvas']['groups'][0]['scale']['factor'])->toBe(1.5)
    ->and($frames[2]['canvas']['groups'][0])->not->toHaveKey('scale')
    ->and($frames[0]['canvas']['groups'][0]['canvas'])->toBe($frames[1]['canvas']['groups'][0]['canvas']);
  $packets = array_values(array_filter($transport->sent, fn($message) => isset($message->payload['operations'])));
  expect(array_column($packets[1]->payload['operations'], 'kind'))->toBe(['canvas_group'])
    ->and(array_column($packets[2]->payload['operations'], 'kind'))->toBe(['canvas_group']);
});

it('negotiates scale and its preparation budget atomically through nested groups', function () {
  $leaf = createGroupDrawing();
  $scaled = new PresentationCanvas(20, 20, groups: [new CanvasGroup('scale', 0, 0, $leaf,
    scale: new CanvasGroupScale(2, new PresentationSpritePivot(0, 0)))]);
  $nested = new PresentationCanvas(20, 20, groups: [new CanvasGroup('outer', 0, 0, $scaled)]);
  foreach ([[], [RendererSessionConfig::GRAPHICAL_CANVAS],
    [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GROUPS],
    [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_REGION_BUDGET]] as $requirements) {
    expect(fn() => new RendererSessionConfig('Scale', getTestTemporaryDirectory(), protocol: RendererProtocolVersion::V2,
      requiredCapabilities: [...$requirements, RendererSessionConfig::CANVAS_GROUP_SCALE]))->toThrow(InvalidArgumentException::class, 'Group scale');
  }
  expect((new RendererSessionConfig('Scale', getTestTemporaryDirectory(), protocol: RendererProtocolVersion::V2))->getNegotiableCapabilities())
    ->toContain(RendererSessionConfig::CANVAS_GROUP_SCALE);
  [$presenter, $transport] = createGroupPresenter([RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GROUPS]);
  $before = $transport->sent;
  expect(fn() => $presenter->prepareCanvas($nested))->toThrow(RendererProtocolException::class, 'canvas_group_scale')
    ->and($transport->sent)->toBe($before);
  $badTransport = new FakeRendererTransport(); $client = new RendererClient($badTransport);
  $client->start(new RendererSessionConfig('Optional scale', getTestTemporaryDirectory(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GROUPS]));
  $badTransport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
    'capabilities' => [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GROUPS, RendererSessionConfig::CANVAS_GROUP_SCALE]], JSON_THROW_ON_ERROR))];
  $client->pump(); $unbudgeted = new RendererPresentation($client, new RendererGridConfig(2, 1)); $badBefore = $badTransport->sent;
  expect(fn() => $unbudgeted->prepareCanvas($nested))->toThrow(RendererProtocolException::class, 'canvas_region_budget')
    ->and($badTransport->sent)->toBe($badBefore);
  $identity = new PresentationCanvas(20, 20, groups: [new CanvasGroup('identity', 0, 0, $leaf,
    scale: new CanvasGroupScale(1, new PresentationSpritePivot(.5, .5)))]);
  expect($presenter->presentCanvas($identity))->toBeTrue();
});

it('preserves scaled geometry through overlay path rewriting and whole-root placement', function () {
  $leaf = new CanvasImage('body', 'test-sprite.png', new CanvasRectangle(2, 3, 4, 5));
  $scale = new CanvasGroupScale(1.5, new PresentationSpritePivot(.25, .5));
  $color = new CanvasColorTransform([[.5, 0, 0], [0, .5, 0], [0, 0, .5]], [0, 0, 0]);
  $child = new PresentationCanvas(20, 20, [$leaf]);
  $marker = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicator('mark', 'body',
    \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind::OUTLINE, new CanvasRectangle(2, 3, 4, 5), 1,
    \Ichiloto\Engine\Rendering\Presentation\PresentationColor::rgb(255, 255, 255), imagePath: ['body-group', 'body']);
  $source = new PresentationCanvas(20, 20, groups: [new CanvasGroup('body-group', 1.25, -2.5, $child, colorTransform: $color, scale: $scale),
    new CanvasGroup('marker-group', 0, 0, new PresentationCanvas(20, 20, indicators: [$marker]), scale: $scale)]);
  $overlay = PresentationCanvas::composeOverlay(new PresentationCanvas(20, 20), $source, 'modal-');
  $placed = CanvasGroup::wrapRoot('field', 0, 0, $overlay, scale: $scale);
  $group = $placed->canvas->groups[0];
  expect($group->scale)->toBe($scale)->and($group->colorTransform)->toBe($color)
    ->and($group->projectRectangle($leaf->destination)->toArray())->toBe(['x' => 1.75, 'y' => -3.0, 'width' => 6.0, 'height' => 7.5])
    ->and($placed->canvas->groups[1]->scale)->toBe($scale)
    ->and($placed->canvas->groups[1]->canvas->indicators[0]->imagePath)->toBe(['field', 'modal-body-group', 'body']);
});


it('charges scaled reused content in child pixels and retains the aggregate instance limits', function () {
  $leaf = createGroupDrawing(); $color = new CanvasColorTransform([[.5, 0, 0], [0, .5, 0], [0, 0, .5]], [0, 0, 0]);
  $result = [];
  foreach ([1.0, 1.25, 2.0, 1.5] as $factor) {
    $root = new PresentationCanvas(20, 20, groups: [new CanvasGroup('field', 0, 0, $leaf, colorTransform: $color,
      scale: new CanvasGroupScale($factor, new PresentationSpritePivot(.5, .5)))]);
    $result[] = CanvasImagePreflight::inspectCanvases([$root], __DIR__ . '/../Fixtures/Renderer');
  }
  expect($result[1])->toBe($result[0])->and($result[2])->toBe($result[0])->and($result[3])->toBe($result[0]);
  $many = createGroupDrawing(512);
  $first = new CanvasGroup('a', 0, 0, $many, scale: new CanvasGroupScale(2, new PresentationSpritePivot(.5, .5)));
  $second = new CanvasGroup('b', 0, 0, $many, scale: new CanvasGroupScale(3, new PresentationSpritePivot(.5, .5)));
  new PresentationCanvas(20, 20, groups: [$first, $second]);
  expect(fn() => new PresentationCanvas(20, 20, groups: [$first, $second, new CanvasGroup('c', 0, 0, $many)]))
    ->toThrow(InvalidArgumentException::class, 'Aggregate');
});
