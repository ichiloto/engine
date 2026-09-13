<?php

use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function rendererClientEvent(string $type, array $payload = []): RendererEvent
{
  return RendererEvent::fromJson(json_encode(['protocol' => 1, 'type' => $type, ...$payload], JSON_THROW_ON_ERROR));
}

it('delegates renderer lifecycle and sends without implementing process control', function () {
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $session = new RendererSessionConfig('Client test', sys_get_temp_dir());
  $client->start($session);
  expect($client->isRunning())->toBeTrue()->and($transport->session)->toBe($session);
  $client->pump();
  expect($client->pollKey())->toBeNull()->and($client->pollEvents())->toBe([]);
  $message = new RendererMessage(RendererMessageType::FRAME, ['opaque' => 'test payload']);
  $client->send($message);
  expect($transport->sent)->toBe([$message]);
  $transport->batches[] = [rendererClientEvent('close_requested')];
  expect($client->shutdown())->toBe(0)->and($client->shutdown())->toBe(0)
    ->and($client->isRunning())->toBeFalse();
  expect($client->pollEvents()[0]->type)->toBe(RendererEventType::CLOSE_REQUESTED);
});

it('routes keys and non-input events separately without stealing either stream', function ($nonKey) {
  $transport = new FakeRendererTransport();
  $transport->batches[] = [rendererClientEvent('key', ['key' => 'up']),
    rendererClientEvent($nonKey, $nonKey === 'error' ? ['message' => 'recoverable'] : []),
    rendererClientEvent('key', ['key' => 'q'])];
  $client = new RendererClient($transport);
  $source = new RendererInputSource($client);
  $client->pump();
  expect($source->poll())->toBe(KeyCode::UP)->and($source->poll())->toBe(KeyCode::q);
  expect($transport->polls)->toBe(1);
  $events = $client->pollEvents();
  expect($events)->toHaveCount(1)->and($events[0]->type->value)->toBe($nonKey);
  expect($transport->polls)->toBe(1);
})->with(['ready', 'close_requested', 'error']);

it('maps renderer wire keys through the existing KeyCode vocabulary', function ($key, $expected) {
  $transport = new FakeRendererTransport();
  $transport->batches[] = [rendererClientEvent('key', ['key' => $key])];
  $source = new RendererInputSource(new RendererClient($transport));
  expect($source->poll())->toBe($expected)->and($source->poll())->toBeNull();
})->with([
  ['up', KeyCode::UP], ['down', KeyCode::DOWN], ['left', KeyCode::LEFT], ['right', KeyCode::RIGHT],
  ['w', KeyCode::w], ['a', KeyCode::a], ['s', KeyCode::s], ['d', KeyCode::d],
  ['W', KeyCode::W], ['A', KeyCode::A], ['S', KeyCode::S], ['D', KeyCode::D],
  ['enter', KeyCode::ENTER], ['space', KeyCode::SPACE], ['escape', KeyCode::ESCAPE],
  ['q', KeyCode::q], ['Q', KeyCode::Q],
]);

it('rejects unsupported renderer keys at the input boundary, not in the wire parser', function () {
  $transport = new FakeRendererTransport();
  $transport->batches[] = [rendererClientEvent('key', ['key' => 'move_up']), rendererClientEvent('close_requested')];
  $client = new RendererClient($transport);
  expect(fn() => new RendererInputSource($client)->poll())->toThrow(RendererProtocolException::class, 'move_up');
  expect($client->pollEvents()[0]->type)->toBe(RendererEventType::CLOSE_REQUESTED);
});

it('resets gameplay keys without deleting lifecycle or error events or stopping the renderer', function ($drain) {
  $transport = new FakeRendererTransport();
  $transport->running = true;
  $client = new RendererClient($transport);
  $source = new RendererInputSource($client);
  $transport->batches[] = [rendererClientEvent('key', ['key' => 'up']), rendererClientEvent('close_requested')];
  $client->pump();
  $transport->batches[] = [rendererClientEvent('key', ['key' => 'q']), rendererClientEvent('error', ['message' => 'recoverable'])];
  $source->reset($drain);
  expect($source->poll())->toBe($drain ? null : KeyCode::q);
  expect(array_column($client->pollEvents(), 'type'))->toBe([RendererEventType::CLOSE_REQUESTED, RendererEventType::ERROR]);
  unset($source);
  expect($client->isRunning())->toBeTrue()->and($transport->shutdowns)->toBe(0);
})->with([false, true]);

it('rejects queue overflow explicitly and preserves all previously accepted events', function ($limits, $overflow) {
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport, ...$limits);
  $transport->batches[] = [rendererClientEvent('key', ['key' => 'w']), rendererClientEvent('ready')];
  $client->pump();
  $transport->batches[] = $overflow;
  expect(fn() => $client->pump())->toThrow(RendererTransportException::class, 'capacity exceeded');
  expect($client->pollKey())->toBe('w')->and($client->pollEvents()[0]->type)->toBe(RendererEventType::READY);
  expect(fn() => $client->pollKey())->toThrow(RendererTransportException::class, 'capacity exceeded');
})->with([
  [['maxPendingKeys' => 1], [rendererClientEvent('key', ['key' => 'q'])]],
  [['maxPendingEvents' => 1], [rendererClientEvent('close_requested')]],
  [['maxQueuedBytes' => 1], [rendererClientEvent('error', ['message' => 'too large'])]],
]);

it('propagates fatal transport failures without erasing already queued events', function () {
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $transport->batches[] = [rendererClientEvent('key', ['key' => 'w']), rendererClientEvent('ready')];
  $client->pump();
  $failure = new RendererTransportException('child failed');
  $transport->failure = $failure;
  expect(fn() => $client->pump())->toThrow($failure);
  expect($client->pollKey())->toBe('w')->and($client->pollEvents()[0]->type)->toBe(RendererEventType::READY);
  expect(fn() => $client->pollEvents())->toThrow($failure);
  expect($client->shutdown())->toBe(0);
});

it('does not overflow the key queue while deliberately draining reset input', function () {
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport, maxPendingKeys: 1);
  $transport->batches[] = [rendererClientEvent('key', ['key' => 'up']), rendererClientEvent('key', ['key' => 'q']), rendererClientEvent('close_requested')];
  new RendererInputSource($client)->reset(true);
  expect($client->pollKey())->toBeNull()->and($client->pollEvents()[0]->type)->toBe(RendererEventType::CLOSE_REQUESTED);
});

it('validates broker limits and refuses to discard unread events for a new session', function () {
  expect(fn() => new RendererClient(new FakeRendererTransport(), maxPendingKeys: 0))->toThrow(InvalidArgumentException::class);
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $transport->batches[] = [rendererClientEvent('ready')];
  $client->pump();
  expect(fn() => $client->start(new RendererSessionConfig('new', sys_get_temp_dir())))->toThrow(RendererTransportException::class, 'pending');
  expect($client->pollEvents()[0]->type)->toBe(RendererEventType::READY);
});
