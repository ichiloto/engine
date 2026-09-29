<?php

use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\FieldMetric;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteMotion;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewportFollow;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Core\Vector2;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

function fieldMotionSprite(int $x, int $y, ?float $seconds): PresentationSprite
{
  return new PresentationSprite('player', 'hero.png', $x, $y, 48, 48, PresentationSpriteAnchor::BOTTOM_CENTER, 100,
    motion: $seconds === null ? null : new PresentationSpriteMotion($seconds));
}

function fieldMotionPresentation(array $capabilities): array
{
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Slides', sys_get_temp_dir(), new RendererGridConfig(20, 10),
    RendererProtocolVersion::V2));
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready',
    'capabilities' => $capabilities]))];
  $client->pump();
  return [$transport, new RendererPresentation($client, new RendererGridConfig(20, 10))];
}

it('measures every step in logical field pixels so both axes walk at RPG Maker speed', function () {
  $metric = new FieldMetric();
  expect(FieldMetric::WALK_PIXELS_PER_SECOND)->toBe(180.0)
    ->and($metric->getDistance(Vector2::right()))->toBe(24.0)
    ->and($metric->getDistance(Vector2::up()))->toBe(48.0)
    ->and($metric->getWalkSeconds(Vector2::left()))->toBe(8 / 60)
    ->and($metric->getWalkSeconds(Vector2::down()))->toBe(16 / 60)
    // Field zoom, device scale and window size are applied after the metric; none are inputs to it.
    ->and(new FieldMetric(48, 48)->getWalkSeconds(Vector2::right()))->toBe(16 / 60)
    ->and(fn() => new FieldMetric(0, 48))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new FieldMetric(24, 48, INF))->toThrow(InvalidArgumentException::class);
});

it('describes a slide and a camera follow only as optional presentation fields', function () {
  $still = fieldMotionSprite(3, 4, null);
  $moving = fieldMotionSprite(3, 4, 16 / 60);
  expect($still->toArray())->not->toHaveKey('motion')
    ->and($moving->toArray()['motion'])->toBe(['duration' => 16 / 60])
    ->and($moving->withoutMotion()->toArray())->toBe($still->toArray())
    ->and(fn() => new PresentationSpriteMotion(0.0))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new PresentationSpriteMotion(61.0))->toThrow(InvalidArgumentException::class);

  $field = new FieldViewport(new RendererGridConfig(20, 10));
  $text = [new PresentationTextLayer('npc', 100, []), new PresentationTextLayer(PresentationLayerPolicy::FIELD_PROMPT_ID, 1100, []),
    new PresentationTextLayer('ui:menu', 1000, [])];
  $followed = $field->createViewport($text, [$moving], [], 'map', ['x' => 3, 'y' => 2], 0, 'player');
  expect($followed->toArray()['follow'])->toBe(['spriteId' => 'player', 'textLayerIds' => [PresentationLayerPolicy::FIELD_PROMPT_ID]])
    ->and($followed->withoutFollow()->toArray())->not->toHaveKey('follow')
    // Nothing to follow when the player is not presented, or without a world.
    ->and($field->createViewport($text, [], [], 'map', ['x' => 3, 'y' => 2], 0, 'player')->follow)->toBeNull()
    ->and($field->createViewport($text, [$moving], [], null, ['x' => 0, 'y' => 0], 0, 'player')->follow)->toBeNull()
    ->and(fn() => new PresentationViewport(1.0, 0, 0, new CanvasRectangle(0, 0, 10, 10), [], ['npc'], [], 'map',
      follow: new PresentationViewportFollow('player')))->toThrow(InvalidArgumentException::class);
});

it('sends a slide once with its step and never makes a standing sprite resend', function () {
  [$transport, $presentation] = fieldMotionPresentation(['sprite_source_rect', 'field_motion']);
  $snapshot = new ConsolePresentationSnapshot(20, 10, []);
  expect($presentation->present($snapshot, [fieldMotionSprite(3, 4, null)]))->toBeTrue()
    ->and($presentation->present($snapshot, [fieldMotionSprite(3, 5, 16 / 60)]))->toBeTrue()
    // Standing where the step ended keeps the same value: nothing new is sent.
    ->and($presentation->present($snapshot, [fieldMotionSprite(3, 5, 16 / 60)]))->toBeFalse();
  $put = array_values(array_filter($transport->sent[1]->payload['operations'], static fn(array $op): bool => $op['op'] === 'put'));
  expect($put[0]['value']['motion'])->toBe(['duration' => 16 / 60]);

  [, $legacy] = fieldMotionPresentation(['sprite_source_rect']);
  expect(fn() => $legacy->present($snapshot, [fieldMotionSprite(3, 5, 16 / 60)]))
    ->toThrow(RendererProtocolException::class, 'field_motion');
});
