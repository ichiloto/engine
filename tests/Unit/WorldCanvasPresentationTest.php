<?php

declare(strict_types=1);

use Ichiloto\Engine\Field\{MapLayer, MapLayerSet};
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasImage, CanvasLabel, CanvasRectangle, PresentationCanvas};
use Ichiloto\Engine\Rendering\Presentation\{PresentationSprite, PresentationSpritePivot, PresentationSubjectOrder,
  PresentationViewport, PresentationViewportFollow, PresentationWorld, PresentationWorldCanvas, RendererPresentation, SceneFrameComposition};
use Ichiloto\Engine\Rendering\Presentation\Text\{TextBounds, TextMeasurement, TextMetrics};
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\{RendererEvent, RendererGridConfig, RendererSessionConfig};
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

function createWorldCanvasProbe(string $id = 'board', int $row = 2, int $layer = 10,
  string $layoutId = 'layout-one', ?PresentationCanvas $canvas = null): PresentationWorldCanvas
{
  $paint = new TextBounds(-2, -1, 20, 10);
  $measurement = new TextMeasurement($layoutId, new TextMetrics(18, 8, new TextBounds(0, 0, 18, 10), $paint, $paint));
  $canvas ??= new PresentationCanvas(48, 24,
    [new CanvasImage('board-picture', 'replaceable-board.png', new CanvasRectangle(0, 0, 48, 24))],
    labels: [new CanvasLabel('lettering', $measurement, 4, 3, 1)]);
  return new PresentationWorldCanvas($id, 3, $row, new PresentationSpritePivot(.5, 1), $canvas, $layer);
}

function createWorldCanvasPresenter(array $without = []): array
{
  $grid = new RendererGridConfig(10, 5, 48, 48);
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $session = new RendererSessionConfig('Synthetic groups', sys_get_temp_dir(), $grid, RendererProtocolVersion::V2);
  $client->start($session);
  $caps = array_values(array_diff($session->getNegotiableCapabilities(), $without));
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
    'capabilities' => $caps], JSON_THROW_ON_ERROR))];
  $client->pump();
  $world = PresentationWorld::getFromLayers(new MapLayerSet([new MapLayer('floor', 0, false, 'synthetic', '....')]), 'field');
  $viewport = new PresentationViewport(1.25, 0, 0, new CanvasRectangle(0, 0, 480, 240), worldId: 'field');
  return [new RendererPresentation($client, $grid), $transport, new ConsolePresentationSnapshot(10, 5, []), $world, $viewport, $client];
}

it('ranks actors and groups together by layer ground row and stable ties without using artwork height', function () {
  $actors = array_map(fn($id, $row, $layer) => new PresentationSprite($id, 'replaceable.png', 1, $row, 48, 192, layer: $layer),
    ['low', 'tie-one', 'tie-two', 'front'], [4, 2, 2, -5], [9, 10, 10, 11]);
  $groups = [createWorldCanvasProbe('behind', 1), createWorldCanvasProbe('tie-group', 2), createWorldCanvasProbe('ahead', 3)];
  expect(array_column(PresentationSubjectOrder::getOrderedSubjects($actors, $groups), 'id'))
    ->toBe(['low', 'behind', 'tie-one', 'tie-two', 'tie-group', 'ahead', 'front']);
  $many = array_map(fn($i) => new PresentationSprite('actor-' . $i, 'a.png', 0, 0, 1, 1), range(1, 1024));
  expect(PresentationSubjectOrder::getOrderedSubjects($many, []))->toHaveCount(1024)
    ->and(fn() => PresentationSubjectOrder::getOrderedSubjects($many, [$groups[0]]))->toThrow(InvalidArgumentException::class);
});

it('serializes globally unique mixed ranks and preserves them through capture overlays and resynchronization', function () {
  [$presenter, $transport, $text, $world, $viewport] = createWorldCanvasPresenter();
  $actors = [new PresentationSprite('actor-behind', 'a.png', 1, 1, 48, 192, layer: 10),
    new PresentationSprite('actor-front', 'a.png', 1, 3, 48, 48, layer: 10)];
  $groups = [createWorldCanvasProbe()];
  $viewport = $viewport->selectWorldCanvases(['board']);
  $frame = $presenter->prepareFrame($text, $actors, $viewport, $world, worldCanvases: $groups);
  expect($transport->sent)->toBe([]);
  $presenter->presentFrame($frame);
  $values = $presenter->captureFrame()->values;
  expect($values['sprite']['actor-behind']['order'])->toBe(0)
    ->and($values['world_canvas']['board']['order'])->toBe(1)
    ->and($values['sprite']['actor-front']['order'])->toBe(2)
    ->and($values['world_canvas']['board']['canvas']['labels'][0])->toBe($groups[0]->canvas->labels[0]->toArray());
  $overlay = new PresentationCanvas(480, 240, [new CanvasImage('notice', 'notice.png', new CanvasRectangle(0, 0, 20, 20))]);
  $transport->sent = [];
  $presenter->presentFrame($frame, $overlay);
  expect(array_column($transport->sent[0]->payload['operations'], 'kind'))->toBe(['canvas', 'canvas_image'])
    ->and($presenter->captureFrame()->values['world_canvas'])->toBe($values['world_canvas']);
  $presenter->invalidate();
  $presenter->presentFrame($frame);
  $latest = RetainedFrameState::getLatestFrame([end($transport->sent)]);
  expect($latest['worldCanvases'][0])->toBe($groups[0]->toArray())
    ->and($latest['viewport']['worldCanvasIds'])->toBe(['board']);
});

it('updates the complete local group once for a new label and removes it on menu and transfer boundaries', function () {
  [$presenter, $transport, $text, $world, $viewport] = createWorldCanvasPresenter();
  $viewport = $viewport->selectWorldCanvases(['board']);
  $group = createWorldCanvasProbe();
  $presenter->present($text, viewport: $viewport, world: $world, worldCanvases: [$group]);
  $initial = $transport->sent;
  $transport->sent = [];
  expect($presenter->present($text, viewport: $viewport, world: $world, worldCanvases: [$group]))->toBeFalse();
  $replacement = createWorldCanvasProbe(layoutId: 'translated-replacement');
  $presenter->present($text, viewport: $viewport, world: $world, worldCanvases: [$replacement]);
  expect($transport->sent[0]->payload['operations'])->toHaveCount(1)
    ->and($transport->sent[0]->payload['operations'][0]['kind'])->toBe('world_canvas');
  $presenter->presentCanvas(new PresentationCanvas(48, 24, labels: $replacement->canvas->labels));
  $latest = RetainedFrameState::getLatestFrame([...$initial, ...$transport->sent]);
  expect($latest)->not->toHaveKeys(['worldCanvases', 'viewport', 'worlds'])
    ->and($latest['canvas']['labels'][0]['layoutId'])->toBe('translated-replacement');
  $presenter->present($text);
  expect(RetainedFrameState::getLatestFrame([...$initial, ...$transport->sent]))->not->toHaveKeys(['canvas', 'worldCanvases']);
});

it('rejects missing negotiated group and label capabilities before changing retained state', function (string $missing, bool $screen) {
  [$presenter, $transport, $text, $world, $viewport] = createWorldCanvasPresenter([$missing]);
  $presenter->present($text);
  $before = $presenter->captureFrame();
  $transport->sent = [];
  $group = createWorldCanvasProbe();
  $submit = $screen ? fn() => $presenter->presentCanvas($group->canvas)
    : fn() => $presenter->present($text, viewport: $viewport->selectWorldCanvases(['board']), world: $world, worldCanvases: [$group]);
  expect($submit)->toThrow(RendererProtocolException::class)->and($transport->sent)->toBe([])
    ->and($presenter->captureFrame())->toEqual($before);
})->with([
  'world-groups' => [RendererSessionConfig::WORLD_CANVAS, false],
  'group-labels' => [RendererSessionConfig::CANVAS_LABELS, false],
  'group-layouts' => [RendererSessionConfig::TEXT_LAYOUT, false],
  'group-canvas' => [RendererSessionConfig::GRAPHICAL_CANVAS, false],
  'screen-labels' => [RendererSessionConfig::CANVAS_LABELS, true],
  'screen-layouts' => [RendererSessionConfig::TEXT_LAYOUT, true],
]);

it('rejects aggregate overlay pressure before mutation and retains the original field frame', function () {
  [$presenter, $transport, $text, $world, $viewport] = createWorldCanvasPresenter();
  $groups = array_map(fn($i) => createWorldCanvasProbe('board-' . $i), range(1, 64));
  $viewport = $viewport->selectWorldCanvases(array_column($groups, 'id'));
  $frame = $presenter->prepareFrame($text, viewport: $viewport, world: $world, worldCanvases: $groups);
  $presenter->presentFrame($frame);
  $before = $presenter->captureFrame();
  $transport->sent = [];
  $overflow = new PresentationCanvas(480, 240, labels: $groups[0]->canvas->labels);
  expect(fn() => $presenter->presentFrame($frame, $overflow))->toThrow(InvalidArgumentException::class)
    ->and($transport->sent)->toBe([])->and($presenter->captureFrame())->toEqual($before);
  expect($presenter->presentFrame($frame))->toBeFalse();
});

it('requires a matching world and bounded viewport membership without confusing group ids with sprites', function () {
  [$presenter, $transport, $text, $world, $viewport] = createWorldCanvasPresenter();
  $group = createWorldCanvasProbe();
  foreach ([null, new PresentationViewport(1, 0, 0, $viewport->clipRect, worldId: 'other'),
    $viewport->selectWorldCanvases(['missing'])] as $invalid) {
    expect(fn() => $presenter->prepareFrame($text, viewport: $invalid, world: $world, worldCanvases: [$group]))
      ->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new PresentationViewport(1, 0, 0, $viewport->clipRect, worldCanvasIds: ['board']))
    ->toThrow(InvalidArgumentException::class)
    ->and(fn() => $viewport->selectWorldCanvases(['board', 'board']))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $viewport->selectWorldCanvases(['board'])->assertMembers($text, [$group]))
      ->toThrow(InvalidArgumentException::class);
  expect($transport->sent)->toBe([]);
});

it('retains world membership through camera follow degradation and uses the existing field transform', function () {
  $grid = new RendererGridConfig(10, 5, 48, 48);
  $actor = new PresentationSprite('actor', 'actor.png', 0, 0, 48, 48);
  $viewport = new FieldViewport($grid, 1.5)->createViewport([], [$actor], worldId: 'field',
    worldOrigin: ['x' => 9, 'y' => -3], followSpriteId: 'actor', worldCanvasIds: ['board']);
  expect($viewport->follow)->toBeInstanceOf(PresentationViewportFollow::class)
    ->and($viewport->withoutFollow()->worldCanvasIds)->toBe(['board'])
    ->and($viewport->withoutFollow()->follow)->toBeNull()->and($viewport->worldOriginX)->toBe(9)
    ->and($viewport->worldOriginY)->toBe(-3)->and($viewport->scale)->toBe(1.5);
  $group = createWorldCanvasProbe()->createProjected(9, -3);
  expect([$group->x, $group->y])->toBe([-6, 5]);
});

it('preserves ordinary empty wire payloads and refuses groups in replacement scenes', function () {
  [$presenter, $transport, $text, $world, $viewport] = createWorldCanvasPresenter();
  expect($viewport->toArray())->not->toHaveKey('worldCanvasIds')
    ->and(fn() => new SceneFrameComposition(null, canvas: new PresentationCanvas(48, 24),
      worldCanvases: [createWorldCanvasProbe()]))->toThrow(InvalidArgumentException::class);
  $presenter->present($text, viewport: $viewport, world: $world);
  expect(array_column($transport->sent[0]->payload['operations'], 'kind'))->not->toContain('world_canvas', 'canvas_label');
});

it('coalesces blocked writes to the latest group without losing the world or duplicating child labels', function () {
  [$presenter, $transport, $text, $world, $viewport] = createWorldCanvasPresenter();
  $viewport = $viewport->selectWorldCanvases(['board']);
  $old = createWorldCanvasProbe();
  $new = createWorldCanvasProbe(row: 3, layoutId: 'updated');
  $transport->acceptWrites = false;
  expect($presenter->present($text, viewport: $viewport, world: $world, worldCanvases: [$old]))->toBeFalse()
    ->and($presenter->hasPendingUpload())->toBeTrue();
  expect($presenter->present($text, viewport: $viewport, world: $world, worldCanvases: [$new]))->toBeFalse()
    ->and($transport->sent)->toBe([]);
  $transport->acceptWrites = true;
  for ($tick = 0; $tick < 10 && $presenter->hasPendingUpload(); $tick++) {
    if ($transport->sent !== []) {
      $last = end($transport->sent)->payload;
      $presenter->acknowledge($last['generation'], $last['present']);
    }
    $presenter->present($text, viewport: $viewport, world: $world, worldCanvases: [$new]);
  }
  expect($presenter->hasPendingUpload())->toBeFalse();
  $latest = RetainedFrameState::getLatestFrame($transport->sent);
  expect($latest['worldCanvases'])->toBe([$new->toArray()])
    ->and($latest['worlds'])->toHaveCount(1)->and($latest['viewport']['worldCanvasIds'])->toBe(['board'])
    ->and($presenter->captureFrame()->worldCanvases)->toBe([$new]);
});

it('feeds a correlated project measurement into a field group without advancing presentation for the query', function () {
  [$presenter, $transport, $text, $world, $viewport, $client] = createWorldCanvasPresenter();
  $prototype = createWorldCanvasProbe();
  $spec = new \Ichiloto\Engine\Rendering\Presentation\Text\TextSpec('Translated label',
    [new \Ichiloto\Engine\Rendering\Presentation\Text\FontReference('fonts/replaceable.ttf')],
    12, 'en', \Ichiloto\Engine\Rendering\Presentation\PresentationColor::rgb(32, 20, 10));
  $request = $client->requestTextPreparation($spec);
  expect($request)->not->toBeNull()->and($request->getResult())->toBeNull()
    ->and($presenter->captureFrame()->values)->toBe([]);
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'text_prepared',
    'requestId' => $request->requestId, 'layoutId' => 'measured-layout',
    'metrics' => $prototype->canvas->labels[0]->measurement->metrics->toArray()], JSON_THROW_ON_ERROR))];
  $client->pump();
  $measurement = $request->getResult();
  $canvas = new PresentationCanvas(48, 24, $prototype->canvas->images,
    labels: [new CanvasLabel('lettering', $measurement, 4, 3, 1)]);
  $group = createWorldCanvasProbe(canvas: $canvas);
  $transport->sent = [];
  $presenter->present($text, viewport: $viewport->selectWorldCanvases(['board']), world: $world, worldCanvases: [$group]);
  $latest = RetainedFrameState::getLatestFrame($transport->sent);
  expect($latest['worldCanvases'][0]['canvas']['labels'][0]['layoutId'])->toBe('measured-layout')
    ->and($transport->sent[0]->payload['generation'])->toBe(1)
    ->and($transport->sent[0]->payload['baseGeneration'])->toBe(0);
});
