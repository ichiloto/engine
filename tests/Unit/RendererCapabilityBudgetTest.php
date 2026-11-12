<?php

use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

it('accepts the complete native drawing and input-subscription handshake', function () {
    $session = new RendererSessionConfig('Full handshake', __DIR__, new RendererGridConfig(12, 4),
        RendererProtocolVersion::V2, RendererSessionConfig::EVENT_SUBSCRIPTIONS);
    $capabilities = $session->getNegotiableCapabilities();
    $event = RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
        'capabilities' => $capabilities], JSON_THROW_ON_ERROR));
    $transport = new FakeRendererTransport();
    $transport->batches[] = [$event];
    $client = new RendererClient($transport);
    $client->start($session);
    $client->pump();
    foreach ($capabilities as $capability) { expect($client->supports($capability))->toBeTrue(); }
    expect(count($capabilities))->toBeLessThanOrEqual(RendererEvent::MAX_CAPABILITIES);
    $client->shutdown();
});

it('bounds capability advertisements while accepting a bounded future extension', function () {
    $caps = array_map(static fn(int $id): string => 'extension_' . $id, range(1, RendererEvent::MAX_CAPABILITIES));
    expect(RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => $caps]))->capabilities)->toBe($caps);
    expect(fn() => RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => [...$caps, 'one_too_many']])))
        ->toThrow(RendererProtocolException::class);
    expect(fn() => RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => ['same', 'same']])))
        ->toThrow(RendererProtocolException::class);
});
