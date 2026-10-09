<?php

use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\RetainedPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

it('offers sprite pivots only as an optional protocol two capability', function () {
  $config = new RendererSessionConfig('Pivot', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2);
  expect($config->getNegotiableCapabilities())->toContain(RendererSessionConfig::SPRITE_PIVOT)
    ->and($config->requiredCapabilities)->toBe([])
    ->and(new RendererSessionConfig('Legacy', sys_get_temp_dir())->getNegotiableCapabilities())->not->toContain('sprite_pivot')
    ->and(fn() => new RendererSessionConfig('Legacy', sys_get_temp_dir(), requiredCapabilities: ['sprite_pivot']))
    ->toThrow(InvalidArgumentException::class);
});

it('validates normalized typed pivots without accepting numeric strings or malformed shapes', function () {
  foreach ([['x' => 0, 'y' => 1], ['x' => 1, 'y' => 0], ['x' => .25, 'y' => .625]] as $data) {
    expect(PresentationSpritePivot::fromArray($data)->toArray())->toBe(['x' => (float)$data['x'], 'y' => (float)$data['y']]);
  }
  foreach ([[], ['x' => .5], ['x' => '0.5', 'y' => 1], ['x' => true, 'y' => 1],
    ['x' => .5, 'y' => 1, 'z' => 0]] as $data) {
    expect(fn() => PresentationSpritePivot::fromArray($data))->toThrow(InvalidArgumentException::class);
  }
  foreach ([-.1, 1.1, INF, -INF, NAN] as $bad) {
    expect(fn() => new PresentationSpritePivot($bad, .5))->toThrow(InvalidArgumentException::class)
      ->and(fn() => new PresentationSpritePivot(.5, $bad))->toThrow(InvalidArgumentException::class);
  }
});

it('keeps omitted-pivot serialization unchanged and orders images by cells rather than pivots', function () {
  $plain = new PresentationSprite('behind', 'test.png', 3, 4, 144, 48);
  $pivoted = new PresentationSprite('behind', 'test.png', 3, 4, 144, 48, pivot: new PresentationSpritePivot(.25, .625));
  expect($plain->toArray(pivot: true))->toBe($plain->toArray())
    ->and($pivoted->toArray())->toBe($plain->toArray())
    ->and($pivoted->toArray(pivot: true)['pivot'])->toBe(['x' => .25, 'y' => .625])
    ->and(array_column(PresentationSprite::orderedList([
      new PresentationSprite('front', 'test.png', 3, 5, 144, 48, pivot: new PresentationSpritePivot(0, 0)), $pivoted,
    ]), 'id'))->toBe(['behind', 'front']);
});

it('negotiates pivot uploads and clears a replaced pivot without changing the ground cell', function (bool $supported) {
  $debug = new ReflectionClass(Debug::class)->getStaticProperties();
  $root = sys_get_temp_dir() . '/ichiloto-pivot-wire-' . bin2hex(random_bytes(6));
  mkdir($root);
  Debug::configure(['log_directory' => $root]);
  try {
    $transport = new FakeRendererTransport();
    $client = new RendererClient($transport);
    $client->start(new RendererSessionConfig('Pivot', $root, protocol: RendererProtocolVersion::V2));
    $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
      'capabilities' => $supported ? ['sprite_pivot'] : []], JSON_THROW_ON_ERROR))];
    $client->pump();
    $sender = new RetainedPresentation($client);
    $image = new PresentationSprite('image', 'test.png', 3, 4, 144, 48, pivot: new PresentationSpritePivot(.25, .625));
    $sender->present(new ConsolePresentationChanges(20, 10, true, order: []), [$image], null);
    $puts = array_values(array_filter(end($transport->sent)->payload['operations'], static fn($op) => ($op['kind'] ?? '') === 'sprite'));
    expect($puts[0]['value'])->toMatchArray(['x' => 3, 'y' => 4, 'anchor' => 'bottom_center'])
      ->and($puts[0]['value']['pivot'] ?? null)->toBe($supported ? ['x' => .25, 'y' => .625] : null);
    $sender->present(new ConsolePresentationChanges(20, 10, false), [$image], null);
    expect(is_file($root . '/warning.log'))->toBe(!$supported);
    if (!$supported) {
      expect(substr_count(file_get_contents($root . '/warning.log'), 'lacks sprite_pivot'))->toBe(1);
    }
    $sender->present(new ConsolePresentationChanges(20, 10, false),
      [new PresentationSprite('image', 'test.png', 3, 4, 144, 48)], null);
    if ($supported) {
      expect(end($transport->sent)->payload['operations'][0]['value'])->not->toHaveKey('pivot');
    }
  } finally {
    foreach ($debug as $key => $value) { new ReflectionProperty(Debug::class, $key)->setValue(null, $value); }
    foreach (glob($root . '/*') ?: [] as $file) { unlink($file); }
    rmdir($root);
  }
})->with([false, true]);
