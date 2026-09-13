<?php

use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;

it('holds only immutable sprite intent without reading assets or storing coordinates', function () {
  $definition = new GraphicalSpriteDefinition('nonexistent/Hero.png', 32, 48, layer: 100);
  expect(get_object_vars($definition))->toBe([
    'sourceRect' => null,
    'asset' => 'nonexistent/Hero.png', 'width' => 32, 'height' => 48,
    'anchor' => PresentationSpriteAnchor::BOTTOM_CENTER, 'layer' => 100,
    'sheet' => null,
  ]);
  foreach (get_object_vars($definition) as $property => $value) {
    expect(function () use ($definition, $property, $value) { $definition->$property = $value; })
      ->toThrow(Error::class);
  }
});

it('accepts portable UTF-8 asset names and the full supported geometry range', function ($asset, $width, $height, $layer) {
  $definition = new GraphicalSpriteDefinition($asset, $width, $height, layer: $layer);
  expect($definition->asset)->toBe($asset)->and($definition->width)->toBe($width)
    ->and($definition->height)->toBe($height)->and($definition->layer)->toBe($layer);
})->with([
  ['Hero.png', 1, 4096, -2147483648],
  ["Graphics/H\u{e9}ro art", 4096, 1, 2147483647],
  ['Graphics/like..but-not-traversal.png', 32, 48, 0],
]);

it('keeps graphical definitions and S4 presentation under the same structural validation', function ($asset, $width, $height, $layer) {
  expect(fn() => new GraphicalSpriteDefinition($asset, $width, $height, layer: $layer))
    ->toThrow(InvalidArgumentException::class);
  expect(fn() => new PresentationSprite('player', $asset, 0, 0, $width, $height, layer: $layer))
    ->toThrow(InvalidArgumentException::class);
})->with([
  ['', 32, 48, 0], ['/Hero.png', 32, 48, 0], ['//server/Hero.png', 32, 48, 0],
  ['C:/Hero.png', 32, 48, 0], ['C:Hero.png', 32, 48, 0], ['Graphics\\Hero.png', 32, 48, 0],
  ['https://example.com/Hero.png', 32, 48, 0], ['data:image/png;base64,abc', 32, 48, 0],
  ['../Hero.png', 32, 48, 0], ['Graphics/../Hero.png', 32, 48, 0], ['Graphics/..', 32, 48, 0],
  ["Hero\0.png", 32, 48, 0], ["Hero\xFF.png", 32, 48, 0],
  ['Hero.png', 0, 48, 0], ['Hero.png', -1, 48, 0], ['Hero.png', 4097, 48, 0],
  ['Hero.png', 32, 0, 0], ['Hero.png', 32, -1, 0], ['Hero.png', 32, 4097, 0],
  ['Hero.png', 32, 48, -2147483649], ['Hero.png', 32, 48, 2147483648],
]);
