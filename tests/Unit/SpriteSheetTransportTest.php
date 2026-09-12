<?php

use Ichiloto\Engine\IO\Console\ConsoleFrameSnapshot;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererStartupException;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

it('keeps legacy hello and whole-image sprite shapes unchanged', function () {
  $session = new RendererSessionConfig('Legacy', sys_get_temp_dir());
  $sprite = new PresentationSprite('player', 'hero.png', 4, 3, 56, 56);
  expect($session->hello()->payload)->not->toHaveKey('requiredCapabilities')
    ->and($sprite->toArray())->not->toHaveKey('sourceRect')
    ->and(RendererEvent::fromJson('{"protocol":1,"type":"ready"}')->capabilities)->toBe([]);
});

it('negotiates source rectangles on either transport version and preserves frame-only changes', function ($protocol) {
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $session = new RendererSessionConfig('Sheets', sys_get_temp_dir(), protocol: $protocol,
    requiredCapabilities: [RendererSessionConfig::SPRITE_SOURCE_RECT]);
  $client->start($session);
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => $protocol->value,
    'type' => 'ready', 'capabilities' => ['sprite_source_rect']], JSON_THROW_ON_ERROR))];
  $client->pump();
  $client->drainEvents();
  expect($session->hello()->payload['requiredCapabilities'])->toBe(['sprite_source_rect'])
    ->and($client->supports('sprite_source_rect'))->toBeTrue();
  $presentation = new RendererPresentation($client, new RendererGridConfig(2, 1));
  $snapshot = $protocol === RendererProtocolVersion::V1
    ? new ConsoleFrameSnapshot(2, 1, ['  ']) : new ConsolePresentationSnapshot(2, 1, []);
  foreach ([0, 1] as $frame) {
    $sprite = new PresentationSprite('player', 'south.png', 0, 0, 56, 56,
      sourceRect: new SpriteSourceRect($frame * 256, 0, 256, 256));
    expect($presentation->present($snapshot, [$sprite]))->toBeTrue()
      ->and($presentation->present($snapshot, [$sprite]))->toBeFalse();
  }
  expect($transport->sent)->toHaveCount(2)
    ->and($transport->sent[1]->payload['sprites'][0]['sourceRect']['x'])->toBe(256)
    ->and($transport->sent[0]->payload['sprites'][0]['sourceRect']['x'])->toBe(0)
    ->and($transport->sent[1]->protocol)->toBe($protocol);
  $client->start(new RendererSessionConfig('Legacy restart', sys_get_temp_dir(), protocol: $protocol));
  expect($client->supports('sprite_source_rect'))->toBeFalse();
})->with([RendererProtocolVersion::V1, RendererProtocolVersion::V2]);

it('rejects cropped presentation unless the capability was requested and acknowledged', function ($unsolicited) {
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  if ($unsolicited) {
    $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["sprite_source_rect"]}')];
    $client->pump();
  }
  $presentation = new RendererPresentation($client, new RendererGridConfig(1, 1));
  $sprite = new PresentationSprite('player', 'south.png', 0, 0, 56, 56, sourceRect: new SpriteSourceRect(0, 0, 256, 256));
  expect(fn() => $presentation->present(new ConsolePresentationSnapshot(1, 1, []), [$sprite]))
    ->toThrow(RendererProtocolException::class, 'negotiated')
    ->and($transport->sent)->toBe([]);
})->with([false, true]);

it('rejects missing acknowledgments in both real pipe transport and shared clients', function ($protocol) {
  $session = new RendererSessionConfig('Sheets', sys_get_temp_dir(), protocol: $protocol,
    requiredCapabilities: ['sprite_source_rect']);
  $transport = new ProcessRendererTransport(new RendererProcessConfig([
    PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', 'normal',
  ]));
  try {
    expect(fn() => $transport->start($session))->toThrow(RendererStartupException::class, 'did not acknowledge');
  } finally { $transport->shutdown(); }
  $fake = new FakeRendererTransport();
  $client = new RendererClient($fake);
  $client->start($session);
  $fake->batches[] = [RendererEvent::fromJson('{"protocol":' . $protocol->value . ',"type":"ready"}')];
  expect(fn() => $client->pump())->toThrow(RendererProtocolException::class, 'did not acknowledge');
})->with([RendererProtocolVersion::V1, RendererProtocolVersion::V2]);

it('completes source-rectangle request and acknowledgment over actual process pipes', function ($protocol) {
  $transport = new ProcessRendererTransport(new RendererProcessConfig([
    PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', 'capabilities',
  ]));
  $client = new RendererClient($transport);
  try {
    $client->start(new RendererSessionConfig('Sheets', sys_get_temp_dir(), protocol: $protocol,
      requiredCapabilities: ['sprite_source_rect']));
    $client->pump();
    expect($client->supports('sprite_source_rect'))->toBeTrue()->and($client->shutdown())->toBe(0);
  } finally { $transport->shutdown(); }
})->with([RendererProtocolVersion::V1, RendererProtocolVersion::V2]);

it('rejects malformed capability acknowledgments', function ($capabilities) {
  expect(fn() => RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":' . $capabilities . '}'))
    ->toThrow(RendererProtocolException::class);
})->with(['null', '{}', 'true', '[1]', '["bad name"]', '["sprite_source_rect","sprite_source_rect"]']);

it('rejects malformed or unsupported capability requests', function ($capabilities) {
  expect(fn() => new RendererSessionConfig('Bad', sys_get_temp_dir(), requiredCapabilities: $capabilities))
    ->toThrow(InvalidArgumentException::class);
})->with([[[null]], [[[]]], [['unknown']], [['sprite_source_rect', 'sprite_source_rect']], [['name' => 'sprite_source_rect']]]);
