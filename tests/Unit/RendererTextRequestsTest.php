<?php

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\Text\FontReference;
use Ichiloto\Engine\Rendering\Presentation\Text\TextSpec;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Text\RendererTextPreparationException;
use Ichiloto\Engine\Rendering\Text\RendererTextPreparationRequest;
use Ichiloto\Engine\Rendering\Text\RendererTextPreparationState;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function queryTextSpec(string $text = 'Synthetic sign'): TextSpec
{
  return new TextSpec($text, [new FontReference('fonts/project.ttf')], 12, 'en', PresentationColor::rgb(20, 30, 40));
}

function queryTextEvent(string $type, array $data = []): RendererEvent
{
  return RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => $type, ...$data], JSON_THROW_ON_ERROR));
}

function queryTextReply(RendererTextPreparationRequest $request, string $layout = 'layout-1', float $advance = 20): RendererEvent
{
  return queryTextEvent('text_prepared', ['requestId' => $request->requestId, 'layoutId' => $layout,
    'metrics' => ['advance' => $advance, 'baseline' => 10,
      'lineBounds' => ['x' => 0, 'y' => 0, 'width' => 20, 'height' => 15],
      'inkBounds' => ['x' => -1, 'y' => 2, 'width' => 21, 'height' => 10],
      'paintBounds' => ['x' => -2, 'y' => 1, 'width' => 23, 'height' => 12]]]);
}

/** @return array{RendererClient, FakeRendererTransport} */
function queryTextClient(array $capabilities = [RendererSessionConfig::TEXT_LAYOUT], array $limits = []): array
{
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport, ...$limits);
  $client->start(new RendererSessionConfig('Synthetic text', __DIR__, protocol: RendererProtocolVersion::V2));
  $transport->batches[] = [queryTextEvent('ready', ['capabilities' => $capabilities])];
  $client->pump();
  $client->drainEvents();
  return [$client, $transport];
}

it('negotiates new drawing capabilities without requiring or fabricating them', function () {
  $capabilities = [RendererSessionConfig::TEXT_LAYOUT, RendererSessionConfig::CANVAS_LABELS, RendererSessionConfig::WORLD_CANVAS];
  [$client, $transport] = queryTextClient($capabilities);
  foreach ($capabilities as $capability) { expect($client->supports($capability))->toBeTrue(); }
  expect($transport->session->requiredCapabilities)->toBe([]);
  foreach ($capabilities as $capability) {
    expect(fn() => new RendererSessionConfig('Legacy', __DIR__, requiredCapabilities: [$capability]))
      ->toThrow(InvalidArgumentException::class);
  }
});

it('requires negotiated project text and never substitutes a machine font or grid text', function () {
  [$client, $transport] = queryTextClient([]);
  expect(fn() => $client->requestTextPreparation(queryTextSpec()))->toThrow(LogicException::class, 'text_layout');
  expect($transport->sent)->toBe([]);
});

it('sends a whole typed label without waiting or advancing retained generations', function () {
  [$client, $transport] = queryTextClient();
  $polls = $transport->polls;
  $spec = queryTextSpec();
  $request = $client->requestTextPreparation($spec);
  expect($request->getResult())->toBeNull()->and($request->state)->toBe(RendererTextPreparationState::PENDING)
    ->and($transport->polls)->toBe($polls)->and($transport->sent)->toHaveCount(1);
  $message = $transport->sent[0];
  expect($message->type)->toBe(RendererMessageType::PREPARE_TEXT)
    ->and($message->protocol)->toBe(RendererProtocolVersion::V2)
    ->and($message->payload)->toBe(['requestId' => $request->requestId, 'spec' => $spec->toArray()])
    ->and($message->payload)->not->toHaveKey('generation');
  $transport->batches[] = [queryTextReply($request)];
  $client->pump();
  $polls = $transport->polls;
  expect($request->getResult()->layoutId)->toBe('layout-1')
    ->and($request->getResult()->metrics->paintBounds->x)->toBe(-2.0)
    ->and($request->state)->toBe(RendererTextPreparationState::PREPARED)
    ->and($client->drainEvents())->toBe([])->and($transport->sent)->toHaveCount(1)
    ->and($transport->polls)->toBe($polls);
});

it('correlates out-of-order results while preserving keys lifecycle and acknowledgement streams', function () {
  [$client, $transport] = queryTextClient();
  $first = $client->requestTextPreparation(queryTextSpec('First'));
  $second = $client->requestTextPreparation(queryTextSpec('Second'));
  $close = queryTextEvent('resized');
  $ack = queryTextEvent('frame_ack', ['generation' => 7, 'frame' => 3, 'presented' => true]);
  $transport->batches[] = [queryTextReply($second, 'second'), queryTextEvent('key', ['key' => 'w']),
    $close, queryTextReply($first, 'first'), $ack];
  $client->pump();
  expect($first->getResult()->layoutId)->toBe('first')->and($second->getResult()->layoutId)->toBe('second')
    ->and($client->pollKey())->toBe('w')->and($client->drainEvents())->toBe([$close, $ack]);
});

it('makes resource refusals observable without failing the connection or swallowing diagnostics', function () {
  [$client, $transport] = queryTextClient();
  $request = $client->requestTextPreparation(queryTextSpec());
  $transport->batches[] = [queryTextEvent('text_prepare_failed', ['requestId' => $request->requestId,
    'message' => 'Project font lacks a visible glyph']), queryTextEvent('key', ['key' => 'q'])];
  $client->pump();
  expect(fn() => $request->getResult())->toThrow(RendererTextPreparationException::class, 'visible glyph')
    ->and($request->state)->toBe(RendererTextPreparationState::FAILED)
    ->and($client->pollKey())->toBe('q')->and($client->isRunning())->toBeTrue()
    ->and($client->requestTextPreparation(queryTextSpec('Another label')))->not->toBeNull();
});

it('returns backpressure without retaining a phantom request or polling', function () {
  [$client, $transport] = queryTextClient();
  $transport->acceptWrites = false;
  $polls = $transport->polls;
  for ($i = 0; $i < 70; $i++) { expect($client->requestTextPreparation(queryTextSpec()))->toBeNull(); }
  expect($transport->sent)->toBe([])->and($transport->polls)->toBe($polls);
  $transport->acceptWrites = true;
  $request = $client->requestTextPreparation(queryTextSpec());
  $transport->batches[] = [queryTextReply($request)];
  $client->pump();
  expect($request->getResult()->layoutId)->toBe('layout-1');
});

it('bounds owned and cancelled in-flight requests until their renderer replies', function () {
  [$client, $transport] = queryTextClient();
  $requests = [];
  for ($i = 0; $i < 64; $i++) { $requests[] = $client->requestTextPreparation(queryTextSpec()); }
  foreach ($requests as $request) { $client->releaseTextPreparation($request); }
  expect(fn() => $client->requestTextPreparation(queryTextSpec()))->toThrow(OverflowException::class, '64');
  expect($transport->sent)->toHaveCount(64);
  $transport->batches[] = array_map(static fn($request) => queryTextReply($request), $requests);
  $client->pump();
  expect($transport->sent)->toHaveCount(65)->and($transport->sent[64]->payload)->toBe(['layoutIds' => ['layout-1']]);
  $transport->batches[] = [queryTextEvent('text_released', ['layoutIds' => ['layout-1']])];
  $client->pump();
  expect($client->requestTextPreparation(queryTextSpec()))->not->toBeNull();
});

it('retains late-result cleanup under write backpressure and retries once per pump', function () {
  [$client, $transport] = queryTextClient();
  $request = $client->requestTextPreparation(queryTextSpec());
  $client->releaseTextPreparation($request);
  $transport->acceptWrites = false;
  $transport->batches[] = [queryTextReply($request)];
  $client->pump();
  expect($transport->sent)->toHaveCount(1)->and($request->state)->toBe(RendererTextPreparationState::RELEASED)
    ->and(fn() => $request->getResult())->toThrow(LogicException::class);
  $transport->acceptWrites = true;
  $client->pump();
  expect($transport->sent)->toHaveCount(2)
    ->and($transport->sent[1]->type)->toBe(RendererMessageType::RELEASE_TEXT);
  $transport->batches[] = [queryTextEvent('text_released', ['layoutIds' => ['layout-1']])];
  $client->pump();
  expect($client->drainEvents())->toBe([]);
});

it('releases a cached layout only when its final query owner releases it', function () {
  [$client, $transport] = queryTextClient();
  $first = $client->requestTextPreparation(queryTextSpec());
  $second = $client->requestTextPreparation(queryTextSpec());
  $transport->batches[] = [queryTextReply($first), queryTextReply($second)];
  $client->pump();
  $client->releaseTextPreparation($first);
  expect($transport->sent)->toHaveCount(2)->and($second->getResult()->layoutId)->toBe('layout-1');
  $client->releaseTextPreparation($second);
  expect($transport->sent)->toHaveCount(3)->and($transport->sent[2]->payload)->toBe(['layoutIds' => ['layout-1']]);
});

it('does not release a late cancelled cache hit which another reply in the same batch owns', function () {
  [$client, $transport] = queryTextClient();
  $cancelled = $client->requestTextPreparation(queryTextSpec());
  $live = $client->requestTextPreparation(queryTextSpec());
  $client->releaseTextPreparation($cancelled);
  $transport->batches[] = [queryTextReply($cancelled), queryTextReply($live)];
  $client->pump();
  expect($transport->sent)->toHaveCount(2)->and($live->getResult()->layoutId)->toBe('layout-1');
});

it('preserves cached ownership when replies arrive in separate batches', function () {
  [$client, $transport] = queryTextClient();
  $first = $client->requestTextPreparation(queryTextSpec());
  $second = $client->requestTextPreparation(queryTextSpec());
  $transport->batches[] = [queryTextReply($first)];
  $client->pump();
  $client->releaseTextPreparation($first);
  expect($transport->sent)->toHaveCount(2);
  $transport->batches[] = [queryTextReply($second)];
  $client->pump();
  expect($transport->sent)->toHaveCount(2)->and($second->getResult()->layoutId)->toBe('layout-1');
  $client->releaseTextPreparation($second);
  expect($transport->sent)->toHaveCount(3)->and($transport->sent[2]->payload)->toBe(['layoutIds' => ['layout-1']]);
});

it('does not admit new queries around an unacknowledged bounded cleanup backlog', function () {
  [$client, $transport] = queryTextClient();
  $requests = [];
  for ($i = 0; $i < 64; $i++) { $requests[] = $client->requestTextPreparation(queryTextSpec("Label $i")); }
  $ids = array_map(static fn($index) => "layout-$index", array_keys($requests));
  $transport->batches[] = array_map(static fn($request, $id) => queryTextReply($request, $id), $requests, $ids);
  $client->pump();
  $transport->acceptWrites = false;
  foreach ($requests as $request) { $client->releaseTextPreparation($request); }
  expect(fn() => $client->requestTextPreparation(queryTextSpec()))->toThrow(OverflowException::class)
    ->and($transport->sent)->toHaveCount(64);
  $transport->acceptWrites = true;
  $client->pump();
  expect($transport->sent)->toHaveCount(65)->and($transport->sent[64]->payload)->toBe(['layoutIds' => $ids])
    ->and(fn() => $client->requestTextPreparation(queryTextSpec()))->toThrow(OverflowException::class);
  $transport->batches[] = [queryTextEvent('text_released', ['layoutIds' => $ids])];
  $client->pump();
  expect($client->requestTextPreparation(queryTextSpec()))->not->toBeNull();
});

it('cleans cancelled failures without inventing layout handles and resumes deferred cleanup', function () {
  [$client, $transport] = queryTextClient();
  $prepared = $client->requestTextPreparation(queryTextSpec());
  $cancelled = $client->requestTextPreparation(queryTextSpec('Refused label'));
  $client->releaseTextPreparation($cancelled);
  $transport->batches[] = [queryTextReply($prepared)];
  $client->pump();
  $client->releaseTextPreparation($prepared);
  expect($transport->sent)->toHaveCount(2);
  $transport->batches[] = [queryTextEvent('text_prepare_failed', ['requestId' => $cancelled->requestId,
    'message' => 'Synthetic refusal'])];
  $client->pump();
  expect($transport->sent)->toHaveCount(3)->and($transport->sent[2]->payload)->toBe(['layoutIds' => ['layout-1']])
    ->and($cancelled->state)->toBe(RendererTextPreparationState::RELEASED);
});

it('rejects unsolicited or duplicate preparation replies before admitting other events', function ($duplicate) {
  [$client, $transport] = queryTextClient();
  $request = $client->requestTextPreparation(queryTextSpec());
  $transport->batches[] = $duplicate
    ? [queryTextReply($request), queryTextEvent('key', ['key' => 'w']), queryTextReply($request)]
    : [queryTextEvent('key', ['key' => 'w']), queryTextEvent('text_prepare_failed', ['requestId' => 'unknown', 'message' => 'bad'])];
  expect(fn() => $client->pump())->toThrow(RendererProtocolException::class, 'unknown, duplicate')
    ->and($client->drainEvents())->toBe([])->and($request->state)->toBe(RendererTextPreparationState::RETIRED);
  expect(fn() => $client->pollKey())->toThrow(RendererProtocolException::class);
})->with([false, true]);

it('carries typed text through process pipes without stealing frame feedback or input', function () {
  $capture = tempnam(sys_get_temp_dir(), 'text-query-');
  if ($capture === false) { throw new RuntimeException('Cannot create synthetic pipe capture.'); }
  $transport = new ProcessRendererTransport(new RendererProcessConfig(
    [PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', 'project_text', $capture],
    startupTimeout: 1, shutdownTimeout: 0.5, terminationTimeout: 0.1));
  $client = new RendererClient($transport);
  try {
    $client->start(new RendererSessionConfig('Synthetic text pipes', __DIR__, protocol: RendererProtocolVersion::V2,
      requiredCapabilities: [RendererSessionConfig::TEXT_LAYOUT]));
    expect($client->pollEvents()[0]->type)->toBe(RendererEventType::READY);
    $prepared = $client->requestTextPreparation(queryTextSpec());
    $refused = $client->requestTextPreparation(queryTextSpec('refuse'));
    $client->send(new RendererMessage(RendererMessageType::FRAME,
      ['generation' => 1, 'frame' => 1, 'present' => true, 'reset' => true, 'operations' => []], RendererProtocolVersion::V2));
    $deadline = hrtime(true) + 2_000_000_000;
    $acks = [];
    do {
      $client->pump();
      $acks = [...$acks, ...$client->drainFrameAcknowledgements()];
      if ($acks !== [] && $prepared->state !== RendererTextPreparationState::PENDING
        && $refused->state !== RendererTextPreparationState::PENDING) { break; }
      usleep(1000);
    } while (hrtime(true) < $deadline);
    expect($prepared->getResult()->layoutId)->toBe('pipe-layout')
      ->and($prepared->getResult()->metrics->paintBounds->x)->toBe(-1.0)
      ->and(fn() => $refused->getResult())->toThrow(RendererTextPreparationException::class, 'Synthetic resource refusal')
      ->and($acks)->toHaveCount(1)->and($acks[0]->generation)->toBe(1)
      ->and($client->pollKey())->toBe('up')->and($client->drainEvents())->toBe([]);
    $client->releaseTextPreparation($prepared);
    $client->send(new RendererMessage(RendererMessageType::FRAME,
      ['generation' => 2, 'frame' => 2, 'present' => true, 'reset' => true, 'operations' => []], RendererProtocolVersion::V2));
    $deadline = hrtime(true) + 2_000_000_000;
    do {
      $client->pump();
      $acks = $client->drainFrameAcknowledgements();
      if ($acks !== []) { break; }
      usleep(1000);
    } while (hrtime(true) < $deadline);
    expect($acks)->toHaveCount(1)->and($acks[0]->generation)->toBe(2)
      ->and($client->pollKey())->toBe('up')->and($client->drainEvents())->toBe([])
      ->and($prepared->state)->toBe(RendererTextPreparationState::RELEASED)
      ->and($client->shutdown())->toBe(0)->and($transport->isRunning())->toBeFalse();
    $wire = array_map(static fn($line) => json_decode($line, true, 64, JSON_THROW_ON_ERROR),
      file($capture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    expect(array_column($wire, 'type'))->toBe(['hello', 'prepare_text', 'prepare_text', 'frame', 'release_text', 'frame', 'shutdown'])
      ->and($wire[1]['spec'])->toBe(json_decode(json_encode(queryTextSpec()->toArray(), JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR))
      ->and($wire[1])->not->toHaveKey('generation')
      ->and($wire[4]['layoutIds'])->toBe(['pipe-layout']);
  } finally {
    $transport->shutdown();
    unlink($capture);
  }
});

it('refuses different metrics for the same immutable layout handle', function () {
  [$client, $transport] = queryTextClient();
  $first = $client->requestTextPreparation(queryTextSpec());
  $second = $client->requestTextPreparation(queryTextSpec());
  $transport->batches[] = [queryTextReply($first), queryTextReply($second, advance: 21)];
  expect(fn() => $client->pump())->toThrow(RendererProtocolException::class, 'different measurements');
});

it('keeps prepared replies inside the broker batch byte and event bounds', function ($limits) {
  [$client, $transport] = queryTextClient(limits: $limits);
  $first = $client->requestTextPreparation(queryTextSpec());
  $second = $client->requestTextPreparation(queryTextSpec());
  $transport->batches[] = [queryTextReply($first), queryTextReply($second, 'layout-2')];
  expect(fn() => $client->pump())->toThrow(RendererTransportException::class, 'capacity exceeded')
    ->and($client->drainEvents())->toBe([])
    ->and($first->state)->toBe(RendererTextPreparationState::RETIRED);
})->with([[['maxPendingEvents' => 1]], [['maxQueuedBytes' => 32]]]);

it('matches release replies exactly and preserves already queued input on a bad acknowledgement', function () {
  [$client, $transport] = queryTextClient();
  $request = $client->requestTextPreparation(queryTextSpec());
  $transport->batches[] = [queryTextReply($request), queryTextEvent('key', ['key' => 'w'])];
  $client->pump();
  $client->releaseTextPreparation($request);
  $transport->batches[] = [queryTextEvent('text_released', ['layoutIds' => ['unknown']]), queryTextEvent('key', ['key' => 'q'])];
  expect(fn() => $client->pump())->toThrow(RendererProtocolException::class, 'release reply')
    ->and($client->pollKey())->toBe('w');
  expect(fn() => $client->pollKey())->toThrow(RendererProtocolException::class);
});

it('bounds release acknowledgements rather than growing an unbounded cleanup backlog', function () {
  [$client, $transport] = queryTextClient();
  $requests = [];
  for ($i = 0; $i < 64; $i++) { $requests[] = $client->requestTextPreparation(queryTextSpec()); }
  $transport->batches[] = array_map(static fn($request, $index) => queryTextReply($request, "layout-$index"), $requests, array_keys($requests));
  $client->pump();
  foreach ($requests as $request) { $client->releaseTextPreparation($request); }
  expect(fn() => $client->requestTextPreparation(queryTextSpec()))->toThrow(OverflowException::class);
  $transport->batches[] = array_map(static fn($index) => queryTextEvent('text_released', ['layoutIds' => ["layout-$index"]]), array_keys($requests));
  $client->pump();
  expect($client->requestTextPreparation(queryTextSpec()))->not->toBeNull();
});

it('rejects a foreign or retired ticket without sending a release into another session', function () {
  [$first, $firstTransport] = queryTextClient();
  [$second, $secondTransport] = queryTextClient();
  $request = $first->requestTextPreparation(queryTextSpec());
  expect(fn() => $second->releaseTextPreparation($request))->toThrow(LogicException::class, 'session')
    ->and($secondTransport->sent)->toBe([]);
  $first->shutdown();
  expect($request->state)->toBe(RendererTextPreparationState::RETIRED)
    ->and(fn() => $request->getResult())->toThrow(LogicException::class)
    ->and(fn() => $first->requestTextPreparation(queryTextSpec()))->toThrow(LogicException::class);
});

it('retires ownership on window close even when the transport has not exited yet', function () {
  [$client, $transport] = queryTextClient();
  $request = $client->requestTextPreparation(queryTextSpec());
  $transport->batches[] = [queryTextReply($request), queryTextEvent('close_requested')];
  $client->pump();
  expect($request->state)->toBe(RendererTextPreparationState::RETIRED)
    ->and($client->drainEvents()[0]->type)->toBe(RendererEventType::CLOSE_REQUESTED)
    ->and(fn() => $client->requestTextPreparation(queryTextSpec()))->toThrow(LogicException::class);
});

it('retains unique query identity across restart and rejects an old-session reply', function () {
  [$client, $transport] = queryTextClient();
  $old = $client->requestTextPreparation(queryTextSpec());
  $client->shutdown();
  $client->start(new RendererSessionConfig('Restart', __DIR__, protocol: RendererProtocolVersion::V2));
  $transport->batches[] = [queryTextEvent('ready', ['capabilities' => [RendererSessionConfig::TEXT_LAYOUT]])];
  $client->pump(); $client->drainEvents();
  $current = $client->requestTextPreparation(queryTextSpec());
  expect($current->requestId)->not->toBe($old->requestId);
  $transport->batches[] = [queryTextReply($old)];
  expect(fn() => $client->pump())->toThrow(RendererProtocolException::class, 'retired session');
});

it('retires owned requests on fatal send or receive failure without erasing accepted keys', function ($sending) {
  [$client, $transport] = queryTextClient();
  $request = $client->requestTextPreparation(queryTextSpec());
  $transport->batches[] = [queryTextEvent('key', ['key' => 'w'])];
  $client->pump();
  $failure = new RendererTransportException('Synthetic peer failure');
  if ($sending) {
    $transport->sendFailure = $failure;
    expect(fn() => $client->requestTextPreparation(queryTextSpec()))->toThrow($failure);
  } else {
    $transport->failure = $failure;
    expect(fn() => $client->pump())->toThrow($failure);
  }
  expect($request->state)->toBe(RendererTextPreparationState::RETIRED)->and($client->pollKey())->toBe('w');
})->with([false, true]);
