<?php

use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteMotion;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function liftedSprite(string $id, int $y, int $lift, ?float $seconds = null): PresentationSprite
{
  return new PresentationSprite($id, 'Characters/$Hero.png', 3, $y, 48, 48, PresentationSpriteAnchor::BOTTOM_CENTER, 100,
    motion: $seconds === null ? null : new PresentationSpriteMotion($seconds), lift: $lift);
}

/** @return array{FakeRendererTransport, RendererPresentation} */
function spriteLiftPresentation(array $capabilities): array
{
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Lift', sys_get_temp_dir(), new RendererGridConfig(20, 10),
    RendererProtocolVersion::V2));
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
    'capabilities' => $capabilities]))];
  $client->pump();
  return [$transport, new RendererPresentation($client, new RendererGridConfig(20, 10))];
}

it('offers sprite_lift as a protocol 2 drawing feature only', function () {
  $v2 = new RendererSessionConfig('Lift', sys_get_temp_dir(), new RendererGridConfig(20, 10), RendererProtocolVersion::V2);
  expect($v2->getNegotiableCapabilities())->toContain(RendererSessionConfig::SPRITE_LIFT)
    ->and(new RendererSessionConfig('Lift', sys_get_temp_dir())->getNegotiableCapabilities())
    ->not->toContain(RendererSessionConfig::SPRITE_LIFT)
    ->and(new RendererSessionConfig('Lift', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
      requiredCapabilities: [RendererSessionConfig::SPRITE_LIFT])->requiredCapabilities)->toBe(['sprite_lift'])
    ->and(fn() => new RendererSessionConfig('Lift', sys_get_temp_dir(), requiredCapabilities: [RendererSessionConfig::SPRITE_LIFT]))
    ->toThrow(InvalidArgumentException::class);
});

it('keeps a lift between the sprite and its own height and names it only when asked', function () {
  $lifted = liftedSprite('hero', 4, 6);
  $plain = liftedSprite('hero', 4, 0);
  expect($lifted->toArray())->toBe($plain->toArray())
    ->and($lifted->toArray())->not->toHaveKey('lift')
    ->and($lifted->toArray(true)['lift'])->toBe(6)
    // An unlifted sprite sends nothing new even to a renderer that draws lifts.
    ->and($plain->toArray(true))->toBe($plain->toArray())
    ->and(liftedSprite('hero', 4, 6, 16 / 60)->withoutMotion())->toEqual($lifted)
    ->and(liftedSprite('hero', 4, 48)->lift)->toBe(48)
    ->and(new GraphicalSpriteDefinition('pose.png', 48, 48)->lift)->toBe(0);
  foreach ([-1, 49] as $invalid) {
    expect(fn() => liftedSprite('hero', 4, $invalid))->toThrow(InvalidArgumentException::class, 'lift')
      ->and(fn() => new GraphicalSpriteDefinition('pose.png', 48, 48, lift: $invalid))->toThrow(InvalidArgumentException::class, 'lift');
  }
});

it('orders lifted sprites by the cell they stand on, not where they are drawn', function () {
  $front = liftedSprite('front', 5, 0);
  $behind = liftedSprite('behind', 4, 48);
  expect(array_map(static fn(PresentationSprite $sprite): string => $sprite->id, PresentationSprite::orderedList([$front, $behind])))
    ->toBe(['behind', 'front']);
});

it('sends a lift only to a renderer that negotiated sprite_lift and leaves every other upload byte for byte unchanged', function () {
  $snapshot = new ConsolePresentationSnapshot(20, 10, []);
  $encode = static function (array $capabilities, array $sprites) use ($snapshot): array {
    [$transport, $presentation] = spriteLiftPresentation($capabilities);
    $presentation->present($snapshot, $sprites);
    return array_map(static fn($message): string => $message->encode(), $transport->sent);
  };
  $put = static function (array $capabilities, array $sprites) use ($snapshot): array {
    [$transport, $presentation] = spriteLiftPresentation($capabilities);
    $presentation->present($snapshot, $sprites);
    return array_values(array_filter(end($transport->sent)->payload['operations'],
      static fn(array $operation): bool => $operation['op'] === 'put' && $operation['kind'] === 'sprite'))[0]['value'];
  };
  $legacy = ['sprite_source_rect', 'field_motion'];
  expect($encode($legacy, [liftedSprite('hero', 4, 6, 16 / 60)]))->toBe($encode($legacy, [liftedSprite('hero', 4, 0, 16 / 60)]))
    ->and($put($legacy, [liftedSprite('hero', 4, 6)]))->not->toHaveKey('lift')
    ->and($put([...$legacy, 'sprite_lift'], [liftedSprite('hero', 4, 6, 16 / 60)]))
    ->toMatchArray(['y' => 4, 'lift' => 6, 'motion' => ['duration' => 16 / 60]])
    ->and($put([...$legacy, 'sprite_lift'], [liftedSprite('hero', 4, 0)]))->not->toHaveKey('lift');
});
