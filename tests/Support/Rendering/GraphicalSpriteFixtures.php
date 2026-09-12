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

/** @return array<string, mixed> */
function spriteSheetData(): array
{
  $directions = [];
  foreach (['north' => 17, 'east' => 21, 'south' => 22, 'west' => 21] as $direction => $frames) {
    $directions[$direction] = ['asset' => "Graphics/$direction.png", 'columns' => 5,
      'rows' => $direction === 'north' ? 4 : 5, 'frames' => $frames];
  }
  return ['mode' => 'sheet', 'frameWidth' => 256, 'frameHeight' => 256, 'width' => 56, 'height' => 56,
    'idleFrame' => 0, 'frameDurationMs' => 80, 'stepDurationMs' => 160,
    'anchor' => 'bottom_center', 'layer' => 100, 'directions' => $directions];
}
