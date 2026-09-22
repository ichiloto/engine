<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\{RendererEvent, RendererSessionConfig};
use Ichiloto\Engine\Rendering\Transport\Enumerations\{RendererEventType, RendererProtocolVersion};
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

it('parses only typed V2 window activation events', function () {
  foreach ([true, false] as $active) {
    $event = RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'window_activation', 'active' => $active]));
    expect($event->type)->toBe(RendererEventType::WINDOW_ACTIVATION)->and($event->active)->toBe($active);
  }
  foreach ([['protocol' => 1, 'type' => 'window_activation', 'active' => true],
    ['protocol' => 2, 'type' => 'key', 'key' => 'up', 'active' => true],
    ['protocol' => 2, 'type' => 'window_activation'],
    ...array_map(fn($active) => ['protocol' => 2, 'type' => 'window_activation', 'active' => $active], [null, 1, 'true'])] as $bad) {
    expect(fn() => RendererEvent::fromJson(json_encode($bad)))->toThrow(RendererProtocolException::class);
  }
});

it('requires activation negotiation before admitting an event batch atomically', function () {
  foreach ([[], ['window_activation']] as $caps) {
    $transport = new FakeRendererTransport();
    $client = new RendererClient($transport);
    $client->start(new RendererSessionConfig('Focus', __DIR__, protocol: RendererProtocolVersion::V2, requiredCapabilities: $caps));
    $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => $caps])),
      RendererEvent::fromJson('{"protocol":2,"type":"window_activation","active":false}')];
    if ($caps === []) {
      expect(fn() => $client->pump())->toThrow(RendererProtocolException::class)
        ->and($client->drainEvents())->toBe([])->and($client->supports('window_activation'))->toBeFalse();
    } else {
      $client->pump();
      expect($client->supports('window_activation'))->toBeTrue()->and($client->drainEvents()[1]->active)->toBeFalse();
      $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"window_activation","active":true}')];
      $client->pump();
      expect($client->drainEvents()[0]->active)->toBeTrue();
    }
  }
});

it('rejects activation before ready even when support was requested', function () {
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Focus', __DIR__, protocol: RendererProtocolVersion::V2, requiredCapabilities: ['window_activation']));
  $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"window_activation","active":false}')];
  expect(fn() => $client->pump())->toThrow(RendererProtocolException::class)->and($client->drainEvents())->toBe([]);
});
