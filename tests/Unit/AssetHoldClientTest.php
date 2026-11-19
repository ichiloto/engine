<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Assets\AssetHoldReadiness;
use Ichiloto\Engine\Rendering\Assets\AssetHoldRequest;
use Ichiloto\Engine\Rendering\Assets\AssetHoldResource;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function assetHoldClientEvent(string $type, array $payload = []): RendererEvent
{
  return RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => $type, ...$payload], JSON_THROW_ON_ERROR));
}

function assetHoldClientFeedback(AssetHoldRequest $request, string $state, int $attempt = 1): array
{
  return ['holds' => [[
    'id' => $request->id, 'revision' => $request->revision, 'attempt' => $attempt, 'state' => $state,
    ...($state === 'ended' ? ['reason' => 'changed'] : ['regionBytes' => 4096, 'baseBytes' => 0]),
    ...($state === 'refused' ? ['reason' => 'over_budget'] : []),
  ]], 'holdCapacity' => createTestAssetHoldCapacity(4096, 1)];
}

beforeEach(function () {
  $this->transport = new FakeRendererTransport();
  $this->client = new RendererClient($this->transport);
  $this->owner = new stdClass();
  $this->session = new RendererSessionConfig('Synthetic asset lease', __DIR__,
    protocol: RendererProtocolVersion::V2, requiredCapabilities: [RendererSessionConfig::ASSET_HOLDS]);
  $this->request = $this->client->assetHolds->replaceResources($this->owner,
    ['return' => new AssetHoldResource('fixtures/return.png')])[0];
  $this->client->start($this->session);
  $this->transport->batches[] = [assetHoldClientEvent('ready', ['capabilities' => [RendererSessionConfig::ASSET_HOLDS]])];
  $this->client->pump();
  $this->client->drainEvents();
});

it('delivers an early refusal before later ACK progress overwrites its slot', function () {
  $this->transport->batches[] = [
    assetHoldClientEvent('frame_ack', ['generation' => 1, 'frame' => 0, 'presented' => false,
      ...assetHoldClientFeedback($this->request, 'refused')]),
    assetHoldClientEvent('frame_ack', ['generation' => 2, 'frame' => 1, 'presented' => false]),
    assetHoldClientEvent('frame_ack', ['generation' => 3, 'frame' => 2, 'presented' => true]),
  ];
  $this->client->pump();
  $progress = $this->client->drainFrameAcknowledgements();
  expect($this->client->assetHolds->getReadiness($this->owner))->toBe(AssetHoldReadiness::REFUSED)
    ->and(array_column($progress, 'generation'))->toBe([2, 3])
    ->and(array_column($progress, 'assetFeedback'))->toBe([null, null])
    ->and(array_column($progress, 'assetFeedbackBytes'))->toBe([0, 0]);
});

it('consumes asynchronous hold results without stealing keys or ordinary lifecycle events', function () {
  $this->transport->batches[] = [assetHoldClientEvent('key', ['key' => 'w']),
    assetHoldClientEvent('asset_holds', assetHoldClientFeedback($this->request, 'held')),
    assetHoldClientEvent('error', ['message' => 'recoverable']),
    assetHoldClientEvent('frame_ack', ['generation' => 2, 'frame' => 1, 'presented' => true,
      ...assetHoldClientFeedback($this->request, 'pending')])];
  $this->client->pump();
  expect($this->client->assetHolds->getReadiness($this->owner))->toBe(AssetHoldReadiness::READY)
    ->and($this->client->pollKey())->toBe('w')
    ->and(array_column($this->client->drainEvents(), 'type'))->toBe([RendererEventType::ERROR, RendererEventType::FRAME_ACK]);
});

it('keeps wire order across a changed source attempt and stale ACKs', function () {
  $this->transport->batches[] = [
    assetHoldClientEvent('asset_holds', assetHoldClientFeedback($this->request, 'held')),
    assetHoldClientEvent('asset_holds', assetHoldClientFeedback($this->request, 'ended')),
    assetHoldClientEvent('asset_holds', assetHoldClientFeedback($this->request, 'pending', 2)),
    assetHoldClientEvent('asset_holds', assetHoldClientFeedback($this->request, 'held', 2)),
    assetHoldClientEvent('frame_ack', ['generation' => 1, 'frame' => 0, 'presented' => true,
      ...assetHoldClientFeedback($this->request, 'held')]),
  ];
  $this->client->pump();
  expect($this->client->assetHolds->getReadiness($this->owner))->toBe(AssetHoldReadiness::READY)
    ->and($this->client->assetHolds->getStatuses($this->owner)[0]->attempt)->toBe(2);
});

it('ignores removed requests without making the replacement ready', function () {
  $next = $this->client->assetHolds->replaceResources($this->owner,
    ['return' => new AssetHoldResource('fixtures/replaced.png')])[0];
  $this->transport->batches[] = [assetHoldClientEvent('asset_holds', assetHoldClientFeedback($this->request, 'held'))];
  $this->client->pump();
  expect($this->client->assetHolds->getReadiness($this->owner))->toBe(AssetHoldReadiness::WAITING)
    ->and($this->client->assetHolds->getDesiredRequests())->toBe([$next]);
});

it('accepts readiness and feedback in one ordered batch', function () {
  $this->client->shutdown();
  $this->client->drainEvents();
  $this->client->start($this->session);
  $this->transport->batches[] = [assetHoldClientEvent('ready', ['capabilities' => [RendererSessionConfig::ASSET_HOLDS]]),
    assetHoldClientEvent('asset_holds', assetHoldClientFeedback($this->request, 'held'))];
  $this->client->pump();
  expect($this->client->assetHolds->getReadiness($this->owner))->toBe(AssetHoldReadiness::READY);
});

it('keeps event subscriptions explicit even when the renderer advertises support', function () {
  $this->client->shutdown();
  $this->client->drainEvents();
  $session = new RendererSessionConfig('Unsubscribed', __DIR__, protocol: RendererProtocolVersion::V2);
  $this->client->start($session);
  $this->transport->batches[] = [assetHoldClientEvent('ready', ['capabilities' => [RendererSessionConfig::ASSET_HOLDS]])];
  $this->client->pump();
  expect($session->getNegotiableCapabilities())->not->toContain(RendererSessionConfig::ASSET_HOLDS)
    ->and($this->client->supports(RendererSessionConfig::ASSET_HOLDS))->toBeFalse()
    ->and($this->client->assetHolds->getReadiness($this->owner))->toBe(AssetHoldReadiness::UNSUPPORTED);
  $this->transport->batches[] = [assetHoldClientEvent('asset_holds', assetHoldClientFeedback($this->request, 'held'))];
  expect(fn() => $this->client->pump())->toThrow(RendererProtocolException::class, 'negotiated asset_holds');
});

it('charges every resource reply before ACK coalescing and rejects an oversized batch atomically', function (array $limits) {
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport, ...$limits);
  $client->start($this->session);
  $transport->batches[] = [assetHoldClientEvent('ready', ['capabilities' => [RendererSessionConfig::ASSET_HOLDS]])];
  $client->pump();
  $client->drainEvents();
  $request = $client->assetHolds->replaceResources($this->owner, ['return' => $this->request->resource])[0];
  $transport->batches[] = [assetHoldClientEvent('key', ['key' => 'q'])];
  $client->pump();
  $transport->batches[] = [
    assetHoldClientEvent('frame_ack', ['generation' => 1, 'frame' => 0, 'presented' => false,
      ...assetHoldClientFeedback($request, 'pending')]),
    assetHoldClientEvent('frame_ack', ['generation' => 2, 'frame' => 1, 'presented' => false,
      ...assetHoldClientFeedback($request, 'held')]),
  ];
  expect(fn() => $client->pump())->toThrow(RendererTransportException::class, 'capacity exceeded');
  expect($client->drainFrameAcknowledgements())->toBe([])
    ->and($client->pollKey())->toBe('q')
    ->and($client->assetHolds->getReadiness($this->owner))->toBe(AssetHoldReadiness::WAITING);
})->with(['reply count' => [['maxPendingEvents' => 1]], 'wire bytes' => [['maxQueuedBytes' => 512]]]);

it('rejects contradictory results before committing any new input or ACK progress', function () {
  $this->transport->batches[] = [assetHoldClientEvent('key', ['key' => 'w']),
    assetHoldClientEvent('asset_holds', assetHoldClientFeedback($this->request, 'held')),
    assetHoldClientEvent('asset_holds', assetHoldClientFeedback($this->request, 'refused'))];
  expect(fn() => $this->client->pump())->toThrow(RendererProtocolException::class, 'Contradictory asset hold');
  expect($this->client->drainEvents())->toBe([])
    ->and($this->client->assetHolds->getReadiness($this->owner))->toBe(AssetHoldReadiness::WAITING);
});

it('ends residency on shutdown and demands fresh feedback after reconnect', function () {
  $this->transport->batches[] = [assetHoldClientEvent('asset_holds', assetHoldClientFeedback($this->request, 'held'))];
  $this->client->pump();
  $this->client->shutdown();
  expect($this->client->assetHolds->getReadiness($this->owner))->toBe(AssetHoldReadiness::WAITING)
    ->and($this->client->assetHolds->getDesiredRequests())->toBe([$this->request]);
  $this->client->start($this->session);
  $this->transport->batches[] = [assetHoldClientEvent('ready', ['capabilities' => [RendererSessionConfig::ASSET_HOLDS]])];
  $this->client->pump();
  expect($this->client->assetHolds->getReadiness($this->owner))->toBe(AssetHoldReadiness::WAITING);
});

it('rejects incomplete or misplaced resource feedback at the wire boundary', function () {
  $feedback = assetHoldClientFeedback($this->request, 'held');
  foreach ([['type' => 'asset_holds'], ['type' => 'asset_holds', 'holds' => []],
    ['type' => 'key', 'key' => 'w', ...$feedback],
    ['type' => 'asset_holds', ...$feedback, 'extra' => true]] as $payload) {
    expect(fn() => RendererEvent::fromJson(json_encode(['protocol' => 2, ...$payload], JSON_THROW_ON_ERROR)))
      ->toThrow(RendererProtocolException::class);
  }
});
