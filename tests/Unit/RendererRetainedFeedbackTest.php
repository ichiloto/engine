<?php

use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function retainedFeedback(string $type, array $payload = []): RendererEvent
{
  return RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => $type, ...$payload], JSON_THROW_ON_ERROR));
}

function retainedAck(int $generation, bool $presented = false, int $frame = 1): RendererEvent
{
  return retainedFeedback('frame_ack', compact('generation', 'frame', 'presented'));
}

function retainedRejection(int $generation = 2, int $expectedGeneration = 1): RendererEvent
{
  return retainedFeedback('frame_rejected', [
    'generation' => $generation, 'expectedGeneration' => $expectedGeneration,
    'message' => 'Retained base differs', 'resyncRequired' => true,
  ]);
}

it('decodes staged and presented retained acknowledgements without conflating them', function (bool $presented) {
  $event = retainedAck(PHP_INT_MAX, $presented, PHP_INT_MAX);
  expect($event->type)->toBe(RendererEventType::FRAME_ACK)
    ->and($event->protocol)->toBe(RendererProtocolVersion::V2)
    ->and($event->generation)->toBe(PHP_INT_MAX)->and($event->frame)->toBe(PHP_INT_MAX)
    ->and($event->presented)->toBe($presented)->and($event->message)->toBeNull()
    ->and($event->expectedGeneration)->toBeNull()->and($event->resyncRequired)->toBeNull();
})->with([false, true]);

it('decodes recoverable rejection metadata and protocol two resize notifications', function () {
  $event = retainedRejection(0, 0);
  expect($event->type)->toBe(RendererEventType::FRAME_REJECTED)
    ->and($event->generation)->toBe(0)->and($event->expectedGeneration)->toBe(0)
    ->and($event->resyncRequired)->toBeTrue()->and($event->message)->toBe('Retained base differs')
    ->and($event->frame)->toBeNull()->and($event->presented)->toBeNull();
  $resize = retainedFeedback('resized');
  expect($resize->type)->toBe(RendererEventType::RESIZED)->and($resize->generation)->toBeNull();
});

it('rejects malformed retained feedback without coercing sequences or boolean states', function (array $wire) {
  expect(fn() => RendererEvent::fromJson(json_encode($wire, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)))
    ->toThrow(RendererProtocolException::class);
})->with(function () {
  $ack = ['protocol' => 2, 'type' => 'frame_ack', 'generation' => 1, 'frame' => 0, 'presented' => false];
  $reject = ['protocol' => 2, 'type' => 'frame_rejected', 'generation' => 2,
    'expectedGeneration' => 0, 'message' => 'resync', 'resyncRequired' => true];
  $cases = [];
  foreach (['generation', 'frame'] as $field) {
    foreach ([null, -1, '1', 1.0, false, 1e30] as $value) { $cases[] = [array_replace($ack, [$field => $value])]; }
  }
  foreach ([null, 'false', 0, 1] as $value) { $cases[] = [array_replace($ack, ['presented' => $value])]; }
  $cases[] = [array_replace($ack, ['generation' => 0])];
  $cases[] = [array_replace($ack, ['protocol' => 1])];
  $cases[] = [array_replace($ack, ['expectedGeneration' => 0])];
  foreach (['generation', 'expectedGeneration'] as $field) {
    foreach ([null, -1, '1', 1.0, true, 1e30] as $value) { $cases[] = [array_replace($reject, [$field => $value])]; }
  }
  foreach ([false, null, 1, 'true'] as $value) { $cases[] = [array_replace($reject, ['resyncRequired' => $value])]; }
  $cases[] = [array_replace($reject, ['message' => null])];
  $cases[] = [array_replace($reject, ['protocol' => 1])];
  $cases[] = [array_replace($reject, ['frame' => 1])];
  $cases[] = [['protocol' => 1, 'type' => 'resized']];
  $cases[] = [['protocol' => 2, 'type' => 'key', 'key' => 'up', 'generation' => 1]];
  $cases[] = [['protocol' => 2, 'type' => 'resized', 'presented' => true]];
  foreach ([$ack, $reject] as $wire) {
    foreach (array_diff(array_keys($wire), ['protocol', 'type']) as $field) {
      $missing = $wire; unset($missing[$field]); $cases[] = [$missing];
    }
  }
  return $cases;
});

it('bounds upload acknowledgements independently of lifecycle count and retains input and rejection events', function () {
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport, maxPendingEvents: 3, maxQueuedBytes: 128);
  $client->start(new RendererSessionConfig('Retained feedback', __DIR__, protocol: RendererProtocolVersion::V2,
    requiredCapabilities: [RendererSessionConfig::WINDOW_ACTIVATION]));
  $transport->batches[] = [retainedFeedback('ready', ['capabilities' => [RendererSessionConfig::WINDOW_ACTIVATION]])];
  $client->pump(); $client->drainEvents();
  $batch = [retainedFeedback('key', ['key' => 'up']), retainedFeedback('window_activation', ['active' => false]), retainedRejection(), retainedFeedback('resized')];
  for ($generation = 1; $generation <= 700; $generation++) { $batch[] = retainedAck($generation); }
  $batch[] = retainedAck(650, true, 3);
  $batch[] = retainedAck(600, true, 2); // A delayed older receipt must not regress presented progress.
  $transport->batches[] = $batch;
  $client->pump();
  $acks = $client->drainFrameAcknowledgements();
  expect(array_column($acks, 'generation'))->toBe([650, 700])
    ->and(array_column($acks, 'presented'))->toBe([true, false])
    ->and($client->drainFrameAcknowledgements())->toBe([])->and($transport->polls)->toBe(2);
  expect(new RendererInputSource($client)->poll())->toBe(KeyCode::UP);
  expect(array_column($client->drainEvents(), 'type'))->toBe([
    RendererEventType::WINDOW_ACTIVATION, RendererEventType::FRAME_REJECTED, RendererEventType::RESIZED,
  ])->and($transport->polls)->toBe(2)->and($client->isRunning())->toBeTrue();
  $client->send(new RendererMessage(RendererMessageType::FRAME, ['generation' => 701, 'baseGeneration' => 700, 'reset' => true], RendererProtocolVersion::V2));
  expect($transport->sent)->toHaveCount(1);
});

it('exposes undrained acknowledgements through the existing event API without extra I/O', function () {
  $transport = new FakeRendererTransport(); $client = new RendererClient($transport);
  $transport->batches[] = [retainedAck(1), retainedAck(2, true)];
  $client->pump();
  expect(array_column($client->pollEvents(), 'generation'))->toBe([1, 2])
    ->and($transport->polls)->toBe(1)->and($client->drainFrameAcknowledgements())->toBe([]);
});

it('preserves ACK progress atomically when another event overflows the incoming client batch', function () {
  $transport = new FakeRendererTransport(); $client = new RendererClient($transport, maxPendingKeys: 1);
  $transport->batches[] = [retainedAck(1), retainedFeedback('key', ['key' => 'w'])];
  $client->pump();
  $transport->batches[] = [retainedAck(2), retainedRejection(), retainedFeedback('key', ['key' => 'q'])];
  expect(fn() => $client->pump())->toThrow(RendererTransportException::class, 'capacity');
  expect($client->pollKey())->toBe('w')->and(array_column($client->drainEvents(), 'generation'))->toBe([1]);
});

it('accounts for acknowledgement bytes without cumulative growth across coalescing or drains', function () {
  $transport = new FakeRendererTransport(); $client = new RendererClient($transport, maxQueuedBytes: 34);
  for ($i = 1; $i <= 200; $i++) {
    $transport->batches[] = [retainedAck($i), retainedAck($i, true)];
    $client->pump();
  }
  expect(array_column($client->drainFrameAcknowledgements(), 'generation'))->toBe([200, 200]);
  $transport->batches[] = [retainedFeedback('error', ['message' => str_repeat('x', 34)])];
  $client->pump();
  expect($client->drainEvents()[0]->message)->toHaveLength(34);
  $transport->batches[] = [retainedAck(201), retainedAck(201, true), retainedFeedback('key', ['key' => 'q'])];
  expect(fn() => $client->pump())->toThrow(RendererTransportException::class, 'capacity');
  expect($client->drainFrameAcknowledgements())->toBe([]);
});

it('retains feedback when gameplay input resets and requires it consumed before session restart', function () {
  $transport = new FakeRendererTransport(); $client = new RendererClient($transport);
  $transport->batches[] = [retainedFeedback('key', ['key' => 'up']), retainedAck(50), retainedRejection(), retainedFeedback('resized')];
  $client->resetKeys(true);
  expect(fn() => $client->start(new RendererSessionConfig('Restart', __DIR__)))
    ->toThrow(RendererTransportException::class, 'pending');
  expect(array_column($client->drainEvents(), 'type'))->toBe([
    RendererEventType::FRAME_REJECTED, RendererEventType::RESIZED, RendererEventType::FRAME_ACK,
  ]);
  $client->start(new RendererSessionConfig('Restart', __DIR__, protocol: RendererProtocolVersion::V2));
  $transport->batches[] = [retainedAck(1)]; $client->pump();
  expect($client->drainFrameAcknowledgements()[0]->generation)->toBe(1)->and($client->pollKey())->toBeNull();
});

it('delivers the final acknowledgement drained during client shutdown', function () {
  $transport = new FakeRendererTransport(); $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Final feedback', __DIR__, protocol: RendererProtocolVersion::V2));
  $transport->batches[] = [retainedAck(9, true, 3)];
  expect($client->shutdown())->toBe(0)->and($client->drainFrameAcknowledgements()[0]->generation)->toBe(9);
});

it('requires protocol two for native runtime while leaving low-level protocol one reference DTOs available', function () {
  $process = new RendererProcessConfig(['not-launched']);
  expect(new RendererRuntimeConfig($process, __DIR__)->protocol)->toBe(RendererProtocolVersion::V2);
  expect(fn() => new RendererRuntimeConfig($process, __DIR__, protocol: RendererProtocolVersion::V1))
    ->toThrow(InvalidArgumentException::class, 'protocol 1 full-frame path was removed');
  expect(new RendererSessionConfig('Reference transport', __DIR__, protocol: RendererProtocolVersion::V1)->protocol)
    ->toBe(RendererProtocolVersion::V1);
});

it('carries retained feedback through real process pipes and client input without treating rejection as fatal', function () {
  $transport = new ProcessRendererTransport(new RendererProcessConfig(
    [PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', 'retained_feedback'],
    startupTimeout: 1, shutdownTimeout: 0.5, terminationTimeout: 0.1));
  $client = new RendererClient($transport, maxPendingEvents: 8);
  try {
    $client->start(new RendererSessionConfig('Retained pipes', __DIR__, protocol: RendererProtocolVersion::V2));
    expect($client->pollEvents()[0]->type)->toBe(RendererEventType::READY);
    $client->send(new RendererMessage(RendererMessageType::FRAME,
      ['generation' => 600, 'frame' => 1, 'present' => true, 'reset' => true, 'operations' => []], RendererProtocolVersion::V2));
    $deadline = hrtime(true) + 2_000_000_000;
    $acks = [];
    do {
      $client->pump();
      foreach ($client->drainFrameAcknowledgements() as $ack) { $acks[(int)$ack->presented] = $ack; }
      if (isset($acks[1])) { break; }
      usleep(1000);
    } while (hrtime(true) < $deadline);
    expect($acks[0]->generation)->toBe(599)->and($acks[1]->generation)->toBe(600)
      ->and(new RendererInputSource($client)->poll())->toBe(KeyCode::UP);
    $events = $client->drainEvents();
    expect(array_column($events, 'type'))->toBe([RendererEventType::FRAME_REJECTED, RendererEventType::RESIZED])
      ->and($events[0]->expectedGeneration)->toBe(0)->and($client->isRunning())->toBeTrue();
    $client->send(new RendererMessage(RendererMessageType::FRAME,
      ['generation' => 601, 'frame' => 2, 'present' => true, 'reset' => true, 'operations' => []], RendererProtocolVersion::V2));
    $recovered = [];
    do {
      $client->pump(); $recovered = $client->drainFrameAcknowledgements();
      if ($recovered !== []) { break; }
      usleep(1000);
    } while (hrtime(true) < $deadline);
    expect($recovered[0]->generation)->toBe(601)->and($recovered[0]->presented)->toBeTrue();
    expect($client->shutdown())->toBe(0);
  } finally {
    $transport->shutdown();
  }
});
