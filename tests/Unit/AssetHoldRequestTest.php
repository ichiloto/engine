<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Assets\AssetHoldBase;
use Ichiloto\Engine\Rendering\Assets\AssetHoldRequest;
use Ichiloto\Engine\Rendering\Assets\AssetHoldResource;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageBottomFade;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

dataset('asset hold valid wire', function (): array {
  $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/Renderer/asset-holds-wire-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
  $cases = [];
  foreach ($fixture['validOperations'] as $case) { $cases[$case['name']] = [$case]; }
  return $cases;
});

dataset('asset hold invalid wire', function (): array {
  $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/Renderer/asset-holds-wire-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
  $cases = [];
  foreach ($fixture['invalidOperations'] as $case) { $cases[$case['name']] = [$case]; }
  return $cases;
});

it('reads the same valid lease operations as the renderer contract without opening assets', function (array $case) {
  $request = AssetHoldRequest::readOperation($case['operation']);
  if ($case['operation']['op'] === 'remove') {
    expect($request)->toBeNull();
    return;
  }
  expect($request)->toBeInstanceOf(AssetHoldRequest::class)
    ->and($request->id)->toBe($case['operation']['id'])
    ->and($request->revision)->toBe($case['operation']['value']['revision'])
    ->and($request->resource->asset)->toBe($case['operation']['value']['asset']);
  // Canonical replay is stable even when the wire explicitly spelled identity defaults.
  expect(AssetHoldRequest::readOperation($request->createPutOperation())->createPutOperation())
    ->toEqual($request->createPutOperation());
})->with('asset hold valid wire');

it('rejects the renderer contract malformed lease operation with its field diagnostic', function (array $case) {
  $operation = $case['operation'];
  if (isset($case['rawValue'])) {
    $operation['value'] = json_decode($case['rawValue'], true, flags: JSON_THROW_ON_ERROR);
  }
  expect(fn() => AssetHoldRequest::readOperation($operation))
    ->toThrow(InvalidArgumentException::class, 'asset_hold ' . $case['code']);
})->with('asset hold invalid wire');

it('derives the held pixels from actual image preparation while excluding drawing placement', function () {
  $fade = new CanvasImageBottomFade([.72, .71], .08);
  $rect = new SpriteSourceRect(2, 4, 32, 48);
  $first = new CanvasImage('drawing-a', 'fixtures/replaceable.png', new CanvasRectangle(1, 2, 32, 48),
    sourceRect: $rect, opacity: .3, brightness: .8, flipX: true, bottomFade: $fade);
  $second = new CanvasImage('drawing-b', 'fixtures/replaceable.png', new CanvasRectangle(20, 30, 64, 96),
    layer: 42, sourceRect: $rect, brightness: .8, flipX: true, bottomFade: $fade);
  $a = AssetHoldRequest::fromCanvasImage('scene:lease', 1, $first);
  $b = AssetHoldRequest::fromCanvasImage('scene:lease', 1, $second);
  expect($a->toArray())->toEqual($b->toArray())
    ->and($a->resource->base->brightness)->toBe(.8)->and($a->resource->base->flipX)->toBeTrue()
    ->and($a->resource->base->bottomFade)->toBe($fade)->and($a->resource->sourceRect)->toBe($rect)
    ->and($a->toArray())->not->toHaveKeys(['destination', 'opacity', 'layer']);
});

it('normalizes explicit identity preparation and negative zero without adding authored metadata', function () {
  $omitted = AssetHoldRequest::fromArray('lease', ['revision' => 1, 'asset' => 'fixtures/background.png']);
  $explicit = new AssetHoldRequest('lease', 1, new AssetHoldResource('fixtures/background.png'));
  expect($explicit->toArray())->toEqual($omitted->toArray())
    ->and($explicit->toArray())->not->toHaveKeys(['hash', 'width', 'height', 'base'])
    ->and(json_encode(new AssetHoldBase(-0.0)->toArray(), JSON_THROW_ON_ERROR))->not->toContain('-0');
});

it('declares display size and incoming canvas before drawing without borrowing the outgoing canvas identity', function () {
  $canvas = new \Ichiloto\Engine\Rendering\Assets\AssetHoldCanvas(900, 600,
    \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasViewportFit::CONTAIN);
  $image = new CanvasImage('body', 'fixtures/replaceable.png', new CanvasRectangle(10, 20, 271.4, 352.9),
    sourceRect: new SpriteSourceRect(32, 48, 32, 48));
  $held = AssetHoldResource::fromCanvasImage($image, $canvas);
  expect($held->drawSize->toArray())->toBe(['width' => 271.4, 'height' => 352.9])
    ->and($held->canvas->toArray())->toBe(['width' => 900, 'height' => 600, 'viewportFit' => 'contain'])
    ->and(AssetHoldResource::fromArray($held->toArray()))->toEqual($held);
  $moved = new CanvasImage('different-id', $image->asset, new CanvasRectangle(50, 60, 271.4, 352.9),
    sourceRect: $image->sourceRect, opacity: .25);
  expect(AssetHoldResource::fromCanvasImage($moved, $canvas)->getKey())->toBe($held->getKey());
  $scaled = new CanvasImage('body', $image->asset, new CanvasRectangle(10, 20, 300, 400), sourceRect: $image->sourceRect);
  expect(AssetHoldResource::fromCanvasImage($scaled, $canvas)->getKey())->not->toBe($held->getKey());
  $otherSurface = new \Ichiloto\Engine\Rendering\Assets\AssetHoldCanvas(900, 600,
    \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasViewportFit::NATIVE);
  expect(AssetHoldResource::fromCanvasImage($image, $otherSurface)->getKey())->not->toBe($held->getKey());
});
