<?php

namespace Tests\Support\Rendering;

/** @return array<string, array{asset: string, width: int, height: int, anchor: string, layer: int}> */
function graphicalSpriteData(): array
{
  $data = [];
  foreach (['north', 'east', 'south', 'west'] as $direction) {
    $data[$direction] = [
      'asset' => 'Graphics/Characters/Hero/Field/' . ucfirst($direction) . '.png',
      'width' => 32,
      'height' => 48,
      'anchor' => 'bottom_center',
      'layer' => 100,
    ];
  }

  return $data;
}
