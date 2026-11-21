<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Assets\AssetHoldRequest;
use Ichiloto\Engine\Rendering\Assets\AssetHoldResource;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\RetainedPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

beforeEach(function () {
  $this->transport = new FakeRendererTransport();
  $this->client = new RendererClient($this->transport);
  $this->client->start(new RendererSessionConfig('Synthetic retained assets', __DIR__,
    protocol: RendererProtocolVersion::V2, requiredCapabilities: [RendererSessionConfig::ASSET_HOLDS]));
  $this->transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["asset_holds","graphical_canvas"]}')];
  $this->client->pump();
  $this->client->drainEvents();
  $this->sender = makeRetainedTestPresentation($this->client);
  $this->owner = new stdClass();
  $this->request = $this->client->assetHolds->replaceResources($this->owner,
    ['return' => new AssetHoldResource('fixtures/return.png')])[0];
  $this->canvas = new PresentationCanvas(80, 60, [
    new CanvasImage('visible', 'fixtures/visible.png', new CanvasRectangle(0, 0, 80, 60)),
  ]);
});

it('encodes resource values independently of drawable order and diffs their release', function () {
  expect($this->sender->presentAssetHolds([$this->request]))->toBeTrue();
  $first = end($this->transport->sent)->payload;
  expect($first['operations'])->toBe([$this->request->createPutOperation()]);
  $decoded = json_decode(json_encode($first['operations'][0], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
  expect(AssetHoldRequest::readOperation($decoded))->toEqual($this->request)
    ->and($decoded['value'])->not->toHaveKeys(['id', 'order']);
  $this->transport->acknowledgeLatestFrame($this->sender);
  expect($this->sender->presentAssetHolds([$this->request]))->toBeFalse();
  expect($this->sender->presentAssetHolds([]))->toBeTrue();
  expect(end($this->transport->sent)->payload['operations'])
    ->toBe([['op' => 'remove', 'kind' => 'asset_hold', 'id' => $this->request->id]]);
});

it('preloads resources while preserving the complete last drawn canvas', function () {
  $this->sender->presentCanvas($this->canvas);
  $this->transport->acknowledgeLatestFrame($this->sender);
  $before = $this->sender->captureFrame();
  $this->sender->presentAssetHolds([$this->request]);
  $after = $this->sender->captureFrame();
  expect($after->canvas)->toBe($before->canvas)
    ->and($after->values['canvas_image'])->toBe($before->values['canvas_image'])
    ->and($after->assetHolds)->toBe([$this->request])
    ->and(end($this->transport->sent)->payload['operations'])->toBe([$this->request->createPutOperation()]);
});

it('does not send an empty resource frame before the first scene', function () {
  expect($this->sender->presentAssetHolds([]))->toBeFalse()->and($this->transport->sent)->toBe([]);
});

it('prepares and restores resource snapshots without changing the visible composition', function () {
  $this->sender->presentCanvas($this->canvas, assetHolds: [$this->request]);
  $this->transport->acknowledgeLatestFrame($this->sender);
  $before = $this->sender->captureFrame();
  $other = new AssetHoldRequest('next', 900, new AssetHoldResource('fixtures/next.png'));
  $prepared = $this->sender->prepareCanvas(new PresentationCanvas(80, 60), assetHolds: [$other]);
  expect($prepared->assetHolds)->toBe([$other])
    ->and($this->sender->captureFrame())->toEqual($before);
  $this->sender->presentFrame($prepared);
  expect($this->sender->captureFrame()->assetHolds)->toBe([$other]);
});

it('rejects invalid resources and foreign frames before changing desired state', function () {
  $this->sender->presentCanvas($this->canvas, assetHolds: [$this->request]);
  $before = $this->sender->captureFrame();
  expect(fn() => $this->sender->presentAssetHolds([$this->request, $this->request]))->toThrow(InvalidArgumentException::class);
  $foreign = makeRetainedTestPresentation($this->client)->prepareCanvas(new PresentationCanvas(80, 60));
  expect(fn() => $this->sender->presentFrame($foreign))->toThrow(LogicException::class, 'belongs');
  expect($this->sender->captureFrame())->toEqual($before);
});

it('replays the complete typed resource set in a bounded multi-chunk reset', function () {
  $requests = [];
  for ($n = 0; $n < 1024; $n++) {
    $requests[] = new AssetHoldRequest('lease-' . $n, $n + 1,
      new AssetHoldResource('fixtures/' . str_repeat('x', 3900) . $n . '.png'));
  }
  $this->sender->presentAssetHolds($requests);
  for ($tick = 0; $tick < 100 && $this->sender->hasPendingUpload(); $tick++) {
    $this->transport->acknowledgeLatestFrame($this->sender);
    $this->sender->presentAssetHolds($requests);
  }
  expect($this->sender->hasPendingUpload())->toBeFalse();
  $packets = array_map(static fn($message) => $message->payload, $this->transport->sent);
  expect(count($packets))->toBeGreaterThan(1)
    ->and(array_column($packets, 'reset'))->toBe([true, ...array_fill(0, count($packets) - 1, false)])
    ->and(array_column($packets, 'present'))->toBe([...array_fill(0, count($packets) - 1, false), true]);
  $actual = [];
  foreach ($packets as $packet) {
    foreach ($packet['operations'] as $operation) {
      $actual[] = AssetHoldRequest::readOperation(json_decode(json_encode($operation, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }
  }
  expect($actual)->toEqual($requests);
  $this->transport->acknowledgeLatestFrame($this->sender);
  $this->transport->sent = [];
  $this->sender->invalidate();
  $this->sender->presentAssetHolds($requests);
  expect($this->transport->sent[0]->payload['reset'])->toBeTrue()
    ->and($this->transport->sent[0]->payload['operations'][0])->toBe($requests[0]->createPutOperation());
});

it('keeps frozen resources alive across producer replacement and preserves incoming preloads', function () {
  $presentation = new RendererPresentation($this->client, new RendererGridConfig());
  $bounds = new CanvasRectangle(0, 0, 80, 60);
  $presentation->presentCanvas(new PresentationCanvas(80, 60, [
    new CanvasImage('outgoing', $this->request->resource->asset, $bounds),
  ]));
  $this->transport->acknowledgeLatestFrame($presentation);
  $frozen = $presentation->captureFrame();
  $next = $this->client->assetHolds->replaceResources($this->owner,
    ['return' => new AssetHoldResource('fixtures/next.png')])[0];
  expect($this->client->assetHolds->getDesiredRequests())->toContain($this->request, $next);
  $presentation->presentFrame($frozen);
  $this->transport->acknowledgeLatestFrame($presentation);
  expect($presentation->captureFrame()->assetHolds)->toContain($this->request, $next);
  $presentation->presentCanvas(new PresentationCanvas(80, 60, [
    new CanvasImage('incoming', $next->resource->asset, $bounds),
  ]));
  expect($this->client->assetHolds->getDesiredRequests())->toBe([$next]);
  $this->client->assetHolds->releaseOwner($this->owner);
  expect($this->client->assetHolds->getDesiredRequests())->toBe([$next]);
  $presentation->releaseAssetHolds();
  expect($this->client->assetHolds->getDesiredRequests())->toBe([]);
});

it('refuses to resurrect an obsolete prepared resource after every owner releases it', function () {
  $presentation = new RendererPresentation($this->client, new RendererGridConfig());
  $prepared = $presentation->prepareCanvas(new PresentationCanvas(80, 60, [
    new CanvasImage('outgoing', $this->request->resource->asset, new CanvasRectangle(0, 0, 80, 60)),
  ]));
  $this->client->assetHolds->releaseOwner($this->owner);
  expect(fn() => $presentation->presentFrame($prepared))->toThrow(LogicException::class, 'currently owned request');
  expect($this->transport->sent)->toBe([])
    ->and($this->client->assetHolds->getDesiredRequests())->toBe([]);
});

it('keeps unrelated preloads with their producer across a frozen canvas replay', function () {
  $presentation = new RendererPresentation($this->client, new RendererGridConfig());
  $prepared = $presentation->prepareCanvas($this->canvas);
  $presentation->presentFrame($prepared);
  expect($presentation->captureFrame()->assetHolds)->toBe([$this->request])
    ->and($prepared->getReferencedAssetHolds())->toBe([]);
  $this->client->assetHolds->releaseOwner($this->owner);
  expect($this->client->assetHolds->getDesiredRequests())->toBe([]);
  $this->transport->acknowledgeLatestFrame($presentation);
  $presentation->presentFrame($prepared);
  expect(end($this->transport->sent)->payload['operations'])
    ->toContain(['op' => 'remove', 'kind' => 'asset_hold', 'id' => $this->request->id]);
});

it('releases an older preparation when the visible source is reread', function () {
  $presentation = new RendererPresentation($this->client, new RendererGridConfig());
  $canvas = new PresentationCanvas(80, 60, [
    new CanvasImage('outgoing', $this->request->resource->asset, new CanvasRectangle(0, 0, 80, 60)),
  ]);
  $presentation->presentCanvas($canvas);
  $this->transport->acknowledgeLatestFrame($presentation);
  $replacement = $this->client->assetHolds->rereadResource($this->owner, 'return');
  expect($this->client->assetHolds->getDesiredRequests())->toContain($this->request, $replacement);
  $presentation->presentCanvas($canvas);
  expect($this->client->assetHolds->getDesiredRequests())->toBe([$replacement])
    ->and($presentation->captureFrame()->getReferencedAssetHolds())->toBe([$replacement]);
});


it('refuses an oversized direct retained snapshot before changing desired resources or queuing any frame', function () {
  $before = $this->sender->captureFrame(); $sent = $this->transport->sent;
  $resource = new AssetHoldResource('a/' . str_repeat("\x01", 4094));
  $requests = [];
  for ($i = 0; $i < 256; $i++) { $requests[] = new AssetHoldRequest('long-' . $i, $i + 1, $resource); }
  expect(fn() => $this->sender->presentAssetHolds($requests))->toThrow(InvalidArgumentException::class, 'transaction_limit');
  expect($this->sender->captureFrame()->assetHolds)->toBe($before->assetHolds)
    ->and($this->transport->sent)->toBe($sent);
});
